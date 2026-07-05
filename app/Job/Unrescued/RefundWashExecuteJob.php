<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRefundRecord;
use App\Model\Unrescued\UnrescuedWashConfig;
use App\Model\Unrescued\UnrescuedWashLog;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class RefundWashExecuteJob extends AbstractJob
{
    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $service = $container->get(UnrescuedRecordService::class);

        $period = $service->normalizePeriod((string) ($this->params['settlement_period'] ?? ''));
        $configId = (int) ($this->params['config_id'] ?? 0);
        $createdBy = (int) ($this->params['created_by'] ?? 0);

        try {
            $this->startTask();
            if ($period === '') {
                throw new \RuntimeException('清算期不能为空');
            }
            $config = UnrescuedWashConfig::query()->find($configId);
            if (!$config) {
                throw new \RuntimeException('清洗规则配置不存在');
            }

            $rules = $service->normalizeWashRules((array) ($config->data ?? []), $service->refundWashRules());
            $ruleByCode = [];
            foreach ($rules as $rule) {
                if (!empty($rule['code'])) {
                    $ruleByCode[(string) $rule['code']] = $rule;
                }
            }
            $total = UnrescuedRefundRecord::query()->where('settlement_period', $period)->count();
            $summary = [];

            UnrescuedRefundRecord::query()
                ->where('settlement_period', $period)
                ->update([
                    'exclude_status' => UnrescuedRecordService::EXCLUDE_NO,
                    'exclude_rule_code' => null,
                    'remark' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $excluded = 0;
            if (($ruleByCode['total_fee_offset_pair']['enabled'] ?? false) === true) {
                $excluded = $this->excludeOffsetPairs($period, $ruleByCode['total_fee_offset_pair'], $summary);
            }
            $processed = 0;
            $medicalAssistanceRule = $ruleByCode['medical_assistance_positive'] ?? null;
            $medicalAssistanceEnabled = ($medicalAssistanceRule['enabled'] ?? false) === true;

            UnrescuedRefundRecord::query()
                ->where('settlement_period', $period)
                ->where('exclude_status', '!=', UnrescuedRecordService::EXCLUDE_YES)
                ->orderBy('id')
                ->chunk(500, function ($records) use ($service, $rules, $medicalAssistanceRule, $medicalAssistanceEnabled, &$processed, &$excluded, &$summary, $total) {
                    $updates = [];
                    foreach ($records as $record) {
                        $processed++;
                        $rule = null;
                        if ($medicalAssistanceEnabled && (float) $record->medical_assistance_pay > 0) {
                            $rule = $medicalAssistanceRule;
                        } else {
                            $rule = $service->matchWashRule($record, $rules);
                        }
                        if (!$rule) {
                            continue;
                        }
                        $code = (string) ($rule['code'] ?? 'unknown');
                        $updates[] = [
                            'id' => (int) $record->id,
                            'code' => $code,
                            'remark' => (string) ($rule['remark'] ?? ''),
                        ];
                        $summary[$code] = ($summary[$code] ?? 0) + 1;
                        $excluded++;
                    }
                    $this->batchMarkExcluded($updates);
                    $this->updateProgress($this->uuid, $total > 0 ? min(($processed / $total) * 100, 99.9) : 99.9);
                });

            UnrescuedWashLog::create([
                'settlement_period' => $period,
                'config_id' => $configId,
                'batch_no' => date('YmdHis') . mt_rand(1000, 9999),
                'total_count' => $total,
                'excluded_count' => $excluded,
                'kept_count' => $total - $excluded,
                'summary' => $summary,
                'created_by' => $createdBy,
            ]);

            $title = Task::where('uuid', $this->uuid)->value('title') ?: '应补应退明细_清洗_';
            if (!str_contains($title, '(剔除')) {
                $title .= sprintf('(剔除%d/保留%d)', $excluded, $total - $excluded);
            }
            $this->updateTask($this->uuid, [
                'progress' => 100.00,
                'status' => Task::STATUS_COMPLETED,
                'title' => $title,
                'url_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $logger->error('Refund wash failed: ' . $e->getMessage(), ['uuid' => $this->uuid]);
            $this->failTask($e, '应补应退清洗失败');
        } finally {
            $this->releaseLock();
        }
    }

    private function excludeOffsetPairs(string $period, array $rule, array &$summary): int
    {
        $rows = UnrescuedRefundRecord::query()
            ->select(['id', 'id_card', 'total_fee'])
            ->where('settlement_period', $period)
            ->where('total_fee', '!=', 0)
            ->orderBy('id')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            $key = (string) $row->id_card . '|' . number_format(abs((float) $row->total_fee), 2, '.', '');
            $bucket = (float) $row->total_fee < 0 ? 'negative' : 'positive';
            $groups[$key][$bucket][] = (int) $row->id;
        }

        $ids = [];
        foreach ($groups as $group) {
            $positive = $group['positive'] ?? [];
            $negative = $group['negative'] ?? [];
            $pairs = min(count($positive), count($negative));
            for ($i = 0; $i < $pairs; $i++) {
                $ids[] = $positive[$i];
                $ids[] = $negative[$i];
            }
        }

        if ($ids) {
            UnrescuedRefundRecord::query()
                ->whereIn('id', $ids)
                ->update([
                    'exclude_status' => UnrescuedRecordService::EXCLUDE_YES,
                    'exclude_rule_code' => (string) ($rule['code'] ?? 'total_fee_offset_pair'),
                    'remark' => (string) ($rule['remark'] ?? '正负费用抵消'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $summary[(string) ($rule['code'] ?? 'total_fee_offset_pair')] = count($ids);
        }

        return count($ids);
    }

    private function batchMarkExcluded(array $rows): void
    {
        if (!$rows) {
            return;
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            foreach ($chunk as $row) {
                UnrescuedRefundRecord::query()
                    ->where('id', $row['id'])
                    ->update([
                        'exclude_status' => UnrescuedRecordService::EXCLUDE_YES,
                        'exclude_rule_code' => $row['code'],
                        'remark' => $row['remark'],
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }
        }
    }
}

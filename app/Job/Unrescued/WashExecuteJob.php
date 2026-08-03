<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRecord;
use App\Model\Unrescued\UnrescuedWashConfig;
use App\Model\Unrescued\UnrescuedWashLog;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class WashExecuteJob extends AbstractJob
{
    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $service = $container->get(UnrescuedRecordService::class);

        $period = $service->normalizePeriod((string) ($this->params['settlement_period'] ?? ''));
        $townId = (int) ($this->params['town_id'] ?? 0);
        $configId = (int) ($this->params['config_id'] ?? 0);
        $createdBy = (int) ($this->params['created_by'] ?? 0);

        try {
            $this->startTask();
            if ($period === '') {
                throw new \RuntimeException('清算期不能为空');
            }

            $config = UnrescuedWashConfig::query()->find($configId);
            if (!$config) {
                throw new \RuntimeException('筛查规则配置不存在');
            }

            $rules = $service->normalizeWashRules((array) ($config->data ?? []));
            $priorityRule = $service->priorityWashRule($rules);
            $ordinaryRules = $service->withoutPriorityWashRule($rules);
            $enabledMajorDiseaseCodes = $service->enabledMajorDiseaseCodes();
            $total = $this->baseQuery($period, $townId)->count();
            $processed = 0;
            $excluded = 0;
            $priorityKept = 0;
            $summary = [];
            $lastId = 0;
            $chunkSize = 1000;
            $batchNo = date('YmdHis') . mt_rand(1000, 9999);

            $logger->info('Unrescued wash execute start.', [
                'uuid' => $this->uuid,
                'settlement_period' => $period,
                'town_id' => $townId,
                'config_id' => $configId,
                'total_count' => $total,
            ]);

            while (true) {
                $records = $this->baseQuery($period, $townId)
                    ->where('id', '>', $lastId)
                    ->orderBy('id')
                    ->limit($chunkSize)
                    ->get();

                if ($records->isEmpty()) {
                    break;
                }

                $resultRows = [];
                foreach ($records as $record) {
                    $lastId = max($lastId, (int) $record->id);
                    $baseStatus = $service->screeningStatus($record);

                    if ($service->matchesPriorityWashRule($record, $priorityRule, $enabledMajorDiseaseCodes)) {
                        $action = $service->priorityWashAction($priorityRule);
                        $isExcluded = $action === 'exclude';
                        $code = UnrescuedRecordService::PRIORITY_WASH_RULE_CODE;
                        $resultRows[] = [
                            'id' => (int) $record->id,
                            'status' => $baseStatus,
                            'exclude_status' => $isExcluded ? UnrescuedRecordService::EXCLUDE_YES : UnrescuedRecordService::EXCLUDE_NO,
                            'exclude_rule_code' => $code,
                            'remark' => (string) ($priorityRule['remark'] ?? ''),
                        ];
                        $summary[$code] = ($summary[$code] ?? 0) + 1;
                        if ($isExcluded) {
                            $excluded++;
                        } else {
                            $priorityKept++;
                        }
                        continue;
                    }

                    $matched = $service->matchWashRule($record, $ordinaryRules);
                    if ($matched) {
                        $code = (string) ($matched['code'] ?? 'unknown');
                        $resultRows[] = [
                            'id' => (int) $record->id,
                            'status' => $baseStatus,
                            'exclude_status' => UnrescuedRecordService::EXCLUDE_YES,
                            'exclude_rule_code' => $code,
                            'remark' => (string) ($matched['remark'] ?? ''),
                        ];
                        $summary[$code] = ($summary[$code] ?? 0) + 1;
                        $excluded++;
                        continue;
                    }

                    $resultRows[] = [
                        'id' => (int) $record->id,
                        'status' => $baseStatus,
                        'exclude_status' => UnrescuedRecordService::EXCLUDE_NO,
                        'exclude_rule_code' => null,
                        'remark' => null,
                    ];
                }

                $this->flushWashResult($resultRows);
                $processed += $records->count();

                $progress = $total > 0 ? min(($processed / $total) * 100, 99.9) : 99.9;
                $this->updateProgress($this->uuid, $progress);
                $logger->info('Unrescued wash execute progress.', [
                    'uuid' => $this->uuid,
                    'settlement_period' => $period,
                    'processed' => $processed,
                    'total_count' => $total,
                    'excluded_count' => $excluded,
                    'progress' => round($progress, 2),
                ]);
            }

            $log = UnrescuedWashLog::create([
                'settlement_period' => $period,
                'config_id' => $configId,
                'batch_no' => $batchNo,
                'total_count' => $total,
                'excluded_count' => $excluded,
                'kept_count' => $total - $excluded,
                'summary' => $summary,
                'created_by' => $createdBy,
            ]);

            $title = Task::where('uuid', $this->uuid)->value('title') ?: '未救助台账_筛查_筛查规则_';
            if (!str_contains($title, '(剔除')) {
                $title .= sprintf('(剔除%d/优先保留%d/保留%d)', $excluded, $priorityKept, $total - $excluded);
            }
            $this->updateTask($this->uuid, [
                'progress' => 100.00,
                'status' => Task::STATUS_COMPLETED,
                'title' => $title,
                'url_at' => date('Y-m-d H:i:s'),
            ]);

            $logger->info('Unrescued wash execute success.', [
                'uuid' => $this->uuid,
                'settlement_period' => $period,
                'wash_log_id' => $log->id,
                'total_count' => $total,
                'excluded_count' => $excluded,
                'kept_count' => $total - $excluded,
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Unrescued wash execute failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '未救助筛查失败');
        } finally {
            $this->releaseLock();
        }
    }

    private function baseQuery(string $period, int $townId)
    {
        $query = UnrescuedRecord::query()->where('settlement_period', $period);
        if ($townId > 0) {
            $query->where('town_id', $townId);
        }

        return $query;
    }

    private function flushWashResult(array $rows): void
    {
        $now = date('Y-m-d H:i:s');
        Db::beginTransaction();
        try {
            foreach (array_chunk($rows, 500) as $chunk) {
                $this->batchUpdateScreeningResult($chunk, $now);
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    private function batchUpdateScreeningResult(array $rows, string $now): void
    {
        if ($rows === []) {
            return;
        }

        $ids = array_column($rows, 'id');
        $bindings = [];
        $caseStatements = [];
        foreach (['status', 'exclude_status', 'exclude_rule_code', 'remark'] as $field) {
            $case = "`{$field}` = CASE `id`";
            foreach ($rows as $row) {
                $case .= ' WHEN ? THEN ?';
                $bindings[] = $row['id'];
                $bindings[] = $row[$field];
            }
            $caseStatements[] = $case . " ELSE `{$field}` END";
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings[] = $now;
        $bindings = array_merge($bindings, $ids);

        Db::update(
            'UPDATE `unrescued_records` SET ' . implode(', ', $caseStatements) . ", `updated_at` = ? WHERE `id` IN ({$placeholders})",
            $bindings
        );
    }
}

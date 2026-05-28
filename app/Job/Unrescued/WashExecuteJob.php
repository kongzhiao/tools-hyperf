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
                throw new \RuntimeException('清洗规则配置不存在');
            }

            $rules = $service->normalizeWashRules((array) ($config->data ?? []));
            $total = $this->baseQuery($period, $townId)->count();
            $processed = 0;
            $excluded = 0;
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

                $matchedRows = [];
                $keptIds = [];
                foreach ($records as $record) {
                    $lastId = max($lastId, (int) $record->id);
                    $matched = $service->matchWashRule($record, $rules);
                    if ($matched) {
                        $code = (string) ($matched['code'] ?? 'unknown');
                        $matchedRows[] = [
                            'id' => (int) $record->id,
                            'exclude_rule_code' => $code,
                            'remark' => (string) ($matched['remark'] ?? ''),
                        ];
                        $summary[$code] = ($summary[$code] ?? 0) + 1;
                        $excluded++;
                        continue;
                    }

                    $keptIds[] = (int) $record->id;
                }

                $this->flushWashResult($matchedRows, $keptIds);
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

            $title = Task::where('uuid', $this->uuid)->value('title') ?: '未救助台账_执行清洗_';
            if (!str_contains($title, '(剔除')) {
                $title .= sprintf('(剔除%d/保留%d)', $excluded, $total - $excluded);
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
            $this->failTask($e, '未救助清洗失败');
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

    private function flushWashResult(array $matchedRows, array $keptIds): void
    {
        $now = date('Y-m-d H:i:s');
        Db::beginTransaction();
        try {
            if (!empty($matchedRows)) {
                foreach (array_chunk($matchedRows, 500) as $chunk) {
                    $this->batchMarkExcluded($chunk, $now);
                }
            }

            if (!empty($keptIds)) {
                foreach (array_chunk($keptIds, 1000) as $ids) {
                    UnrescuedRecord::query()
                        ->whereIn('id', $ids)
                        ->update([
                            'exclude_status' => UnrescuedRecordService::EXCLUDE_NO,
                            'exclude_rule_code' => null,
                            'remark' => null,
                            'updated_at' => $now,
                        ]);
                }
            }

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollBack();
            throw $e;
        }
    }

    private function batchMarkExcluded(array $rows, string $now): void
    {
        $ids = array_column($rows, 'id');
        $bindings = [];
        $ruleCase = '`exclude_rule_code` = CASE `id`';
        $remarkCase = '`remark` = CASE `id`';

        foreach ($rows as $row) {
            $ruleCase .= ' WHEN ? THEN ?';
            $bindings[] = $row['id'];
            $bindings[] = $row['exclude_rule_code'];
        }
        $ruleCase .= ' ELSE `exclude_rule_code` END';

        foreach ($rows as $row) {
            $remarkCase .= ' WHEN ? THEN ?';
            $bindings[] = $row['id'];
            $bindings[] = $row['remark'];
        }
        $remarkCase .= ' ELSE `remark` END';

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bindings[] = UnrescuedRecordService::EXCLUDE_YES;
        $bindings[] = $now;
        $bindings = array_merge($bindings, $ids);

        Db::update(
            "UPDATE `unrescued_records` SET {$ruleCase}, {$remarkCase}, `exclude_status` = ?, `updated_at` = ? WHERE `id` IN ({$placeholders})",
            $bindings
        );
    }
}

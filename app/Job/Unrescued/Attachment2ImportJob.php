<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRecord;
use App\Service\BusinessFilterOptionService;
use App\Service\CsvReaderService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class Attachment2ImportJob extends AbstractJob
{
    private string $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        parent::__construct($params, $uuid);
        $this->tempFile = $tempFile;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $service = $container->get(UnrescuedRecordService::class);
        $filterOptionService = $container->get(BusinessFilterOptionService::class);

        try {
            $this->startTask();
            if (!file_exists($this->tempFile)) {
                throw new \RuntimeException('导入文件不存在');
            }

            $settlementPeriod = $service->normalizePeriod((string) ($this->params['settlement_period'] ?? ''));
            if ($settlementPeriod === '') {
                throw new \RuntimeException('清算期不能为空');
            }

            $csvReader = new CsvReaderService();
            $totalCount = max($csvReader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $sourceBatch = date('YmdHis');
            $result = ['matched_rows' => 0, 'updated_records' => 0, 'skipped' => 0, 'errors' => []];
            $townLookupMap = $service->buildTownLookupMap();
            $pendingRows = [];
            $identityValues = [];
            $chunkSize = 1000;
            $logger->info('Unrescued attachment2 import start.', [
                'uuid' => $this->uuid,
                'settlement_period' => $settlementPeriod,
                'total_count' => $totalCount,
                'source_file' => $this->params['source_file'] ?? '',
            ]);

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $settlementPeriod,
                &$processed,
                $totalCount,
                &$result,
                $logger,
                $townLookupMap,
                &$pendingRows,
                &$identityValues,
                $chunkSize
            ) {
                $processed++;
                try {
                    $idCard = $service->pickValue($row, ['身份证号', '身份证号码', '身份证件号码', '公民身份号码', '身份证', 'id_card']);
                    if ($idCard === '') {
                        $result['skipped']++;
                        return;
                    }

                    $name = $service->pickValue($row, ['姓名', 'name']);
                    $streetTown = $service->pickValue($row, ['镇街', '镇（街）', '镇(街)', '街镇', '街道乡镇', '镇街名称', '乡镇街道', 'street_town']);
                    $village = $service->pickValue($row, ['村社', '村（居）', '村(居)', '社区', '村社区', '村居', 'village']);
                    $identity = $service->pickValue($row, ['优先身份', '医疗救助身份', '救助身份', '对象类别', '对象身份', '身份', '身份类别', '特殊人员身份类别', '特殊人员身份', '人员类别', 'priority_identity']);
                    $townId = $service->resolveTownIdFromMap($streetTown, $townLookupMap);
                    if ($identity !== '') {
                        $identityValues[$identity] = true;
                    }

                    $pendingRows[] = [
                        'row_number' => $rowIndex + 1,
                        'id_card' => $idCard,
                        'name' => $name,
                        'town_id' => $townId,
                        'street_town' => $streetTown,
                        'village' => $village,
                        'priority_identity' => $identity,
                    ];

                    if (count($pendingRows) >= $chunkSize) {
                        $this->flushRows($pendingRows, $settlementPeriod, $result, $logger);
                        $pendingRows = [];
                    }
                } catch (\Throwable $e) {
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Unrescued attachment2 import row failed: ' . $e->getMessage());
                }

                if ($processed % 100 === 0) {
                    $progress = min(($processed / $totalCount) * 100, 99.9);
                    $this->updateProgress($this->uuid, $progress);
                    $logger->info('Unrescued attachment2 import progress.', [
                        'uuid' => $this->uuid,
                        'processed' => $processed,
                        'total_count' => $totalCount,
                        'progress' => round($progress, 2),
                    ]);
                }
            });

            $this->flushRows($pendingRows, $settlementPeriod, $result, $logger);
            foreach (array_keys($identityValues) as $identity) {
                $filterOptionService->saveOption('unrescued', 'priority_identity', $identity, $sourceBatch);
            }

            $logger->info('Unrescued attachment2 import progress.', [
                'uuid' => $this->uuid,
                'processed' => $processed,
                'total_count' => $totalCount,
                'progress' => 100.00,
            ]);
            $logger->info('Unrescued attachment2 import success.', [
                'uuid' => $this->uuid,
                'settlement_period' => $settlementPeriod,
                'result' => $result,
            ]);
            $this->finishImportTask($result);
        } catch (\Throwable $e) {
            $logger->error('Unrescued attachment2 import failed: ' . $e->getMessage(), [
                'uuid' => $this->uuid,
                'params' => $this->params,
            ]);
            $this->failTask($e, '附件2导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function flushRows(array $rows, string $settlementPeriod, array &$result, $logger): void
    {
        if (empty($rows)) {
            return;
        }

        $idCards = array_values(array_unique(array_column($rows, 'id_card')));
        $countRows = UnrescuedRecord::query()
            ->select(['id_card', Db::raw('COUNT(*) AS record_count')])
            ->where('settlement_period', $settlementPeriod)
            ->whereIn('id_card', $idCards)
            ->groupBy('id_card')
            ->get();

        $recordCounts = [];
        foreach ($countRows as $item) {
            $recordCounts[(string) $item->id_card] = (int) $item->record_count;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            try {
                $matchedCount = $recordCounts[$row['id_card']] ?? 0;
                if ($matchedCount <= 0) {
                    $result['skipped']++;
                    continue;
                }

                UnrescuedRecord::query()
                    ->where('settlement_period', $settlementPeriod)
                    ->where('id_card', $row['id_card'])
                    ->update([
                        'town_id' => $row['town_id'],
                        'street_town' => $row['street_town'] !== '' ? $row['street_town'] : null,
                        'village' => $row['village'] !== '' ? $row['village'] : null,
                        'priority_identity' => $row['priority_identity'] !== '' ? $row['priority_identity'] : null,
                        'status' => Db::raw($this->statusCaseSql()),
                        'updated_at' => $now,
                    ]);

                if ($row['name'] !== '') {
                    UnrescuedRecord::query()
                        ->where('settlement_period', $settlementPeriod)
                        ->where('id_card', $row['id_card'])
                        ->where(function ($query) {
                            $query->whereNull('name')
                                ->orWhere('name', '')
                                ->orWhere('name', '--');
                        })
                        ->update([
                            'name' => $row['name'],
                            'updated_at' => $now,
                        ]);
                }

                $result['matched_rows']++;
                $result['updated_records'] += $matchedCount;
            } catch (\Throwable $e) {
                if (count($result['errors']) < 50) {
                    $result['errors'][] = '第' . $row['row_number'] . '行: ' . $e->getMessage();
                }
                $logger->warning('Unrescued attachment2 import row failed: ' . $e->getMessage());
            }
        }
    }

    private function statusCaseSql(): string
    {
        return sprintf(
            "CASE WHEN `status` IN ('%s','%s','%s') THEN `status` WHEN `calc_reimbursement_amount` <= 0 THEN '%s' WHEN `calc_reimbursement_amount` <= 300 THEN '%s' ELSE '%s' END",
            UnrescuedRecordService::STATUS_DISTRIBUTED,
            UnrescuedRecordService::STATUS_RECEIVED,
            UnrescuedRecordService::STATUS_NOTIFIED,
            UnrescuedRecordService::STATUS_NO_AMOUNT,
            UnrescuedRecordService::STATUS_NO_NOTICE,
            UnrescuedRecordService::STATUS_TO_NOTICE
        );
    }

    private function finishImportTask(array $result): void
    {
        $summary = sprintf('(匹配%d行/更新%d条/跳过%d/失败%d)', $result['matched_rows'], $result['updated_records'], $result['skipped'], count($result['errors']));
        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '未救助台账_导入_附件2救助对象名单_';
        if (!str_contains($title, '(')) {
            $title .= $summary;
        }
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'status' => Task::STATUS_COMPLETED,
            'title' => $title,
            'url_at' => date('Y-m-d H:i:s'),
        ]);
    }
}

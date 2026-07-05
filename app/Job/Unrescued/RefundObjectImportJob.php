<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRefundRecord;
use App\Service\CsvReaderService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class RefundObjectImportJob extends AbstractJob
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

        try {
            $this->startTask();
            if (!file_exists($this->tempFile)) {
                throw new \RuntimeException('导入文件不存在');
            }
            $period = $service->normalizePeriod((string) ($this->params['settlement_period'] ?? ''));
            if ($period === '') {
                throw new \RuntimeException('清算期不能为空');
            }

            $reader = new CsvReaderService();
            $townMap = $service->buildTownLookupMap();
            $this->updateProgress($this->uuid, 1);
            $total = max($reader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $result = ['matched_rows' => 0, 'updated_records' => 0, 'skipped' => 0, 'errors' => []];
            $pendingRows = [];
            $chunkSize = 1000;

            $targetIdCards = UnrescuedRefundRecord::query()
                ->where('settlement_period', $period)
                ->whereNotNull('id_card')
                ->where('id_card', '!=', '')
                ->distinct()
                ->pluck('id_card')
                ->map(static fn ($value) => (string) $value)
                ->all();
            $targetIdCardMap = array_fill_keys($targetIdCards, true);

            $logger->info('Refund object import start.', [
                'uuid' => $this->uuid,
                'settlement_period' => $period,
                'total_count' => $total,
                'target_id_card_count' => count($targetIdCardMap),
                'source_file' => $this->params['source_file'] ?? '',
            ]);

            if ($targetIdCardMap === []) {
                $this->finishImportTask($result);
                return;
            }

            $reader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $period,
                $townMap,
                $targetIdCardMap,
                &$processed,
                $total,
                &$result,
                $logger,
                &$pendingRows,
                $chunkSize
            ) {
                $processed++;
                try {
                    $idCard = $service->pickValue($row, ['身份证号', '身份证号码', '身份证件号码', '公民身份号码', '身份证', 'id_card']);
                    if ($idCard === '') {
                        $result['skipped']++;
                        return;
                    }
                    if (!isset($targetIdCardMap[$idCard])) {
                        $result['skipped']++;
                        return;
                    }

                    $streetTown = $service->pickValue($row, ['镇街', '镇（街）', '镇(街)', '街镇', '乡镇街道', 'street_town']);
                    $pendingRows[] = [
                        'row_number' => $rowIndex + 1,
                        'id_card' => $idCard,
                        'name' => $service->pickValue($row, ['姓名', 'name']) ?: null,
                        'town_id' => $service->resolveTownIdFromMap($streetTown, $townMap),
                        'street_town' => $streetTown ?: null,
                        'village' => $service->pickValue($row, ['村社', '村（居）', '社区', '村社区', 'village']) ?: null,
                        'priority_identity' => $service->pickValue($row, ['优先身份', '医疗救助身份', '救助身份', '对象类别', '身份', 'priority_identity']) ?: null,
                    ];

                    if (count($pendingRows) >= $chunkSize) {
                        $this->flushRows($pendingRows, $period, $result, $logger);
                        $pendingRows = [];
                    }
                } catch (\Throwable $e) {
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Refund object import row failed: ' . $e->getMessage());
                }

                if ($processed % 500 === 0) {
                    $progress = min(($processed / $total) * 100, 99.9);
                    $this->updateProgress($this->uuid, $progress);
                    if ($processed % 5000 === 0) {
                        $logger->info('Refund object import progress.', [
                            'uuid' => $this->uuid,
                            'processed' => $processed,
                            'total_count' => $total,
                            'matched_rows' => $result['matched_rows'],
                            'updated_records' => $result['updated_records'],
                            'progress' => round($progress, 2),
                        ]);
                    }
                }
            });

            $this->flushRows($pendingRows, $period, $result, $logger);
            $logger->info('Refund object import success.', [
                'uuid' => $this->uuid,
                'settlement_period' => $period,
                'processed' => $processed,
                'result' => $result,
            ]);
            $this->finishImportTask($result);
        } catch (\Throwable $e) {
            $logger->error('Refund object import failed: ' . $e->getMessage(), ['uuid' => $this->uuid]);
            $this->failTask($e, '应补应退救助对象名单导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function flushRows(array $rows, string $period, array &$result, $logger): void
    {
        if ($rows === []) {
            return;
        }

        $rowsByIdCard = [];
        foreach ($rows as $row) {
            $rowsByIdCard[$row['id_card']] = $row;
        }

        $idCards = array_keys($rowsByIdCard);
        $countRows = UnrescuedRefundRecord::query()
            ->select(['id_card', Db::raw('COUNT(*) AS record_count')])
            ->where('settlement_period', $period)
            ->whereIn('id_card', $idCards)
            ->groupBy('id_card')
            ->get();

        $recordCounts = [];
        foreach ($countRows as $item) {
            $recordCounts[(string) $item->id_card] = (int) $item->record_count;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($rowsByIdCard as $row) {
            try {
                $matchedCount = $recordCounts[$row['id_card']] ?? 0;
                if ($matchedCount <= 0) {
                    $result['skipped']++;
                    continue;
                }

                UnrescuedRefundRecord::query()
                    ->where('settlement_period', $period)
                    ->where('id_card', $row['id_card'])
                    ->update([
                        'name' => $row['name'],
                        'town_id' => $row['town_id'],
                        'street_town' => $row['street_town'],
                        'village' => $row['village'],
                        'priority_identity' => $row['priority_identity'],
                        'match_status' => UnrescuedRecordService::MATCHED,
                        'updated_at' => $now,
                    ]);

                $result['matched_rows']++;
                $result['updated_records'] += $matchedCount;
            } catch (\Throwable $e) {
                if (count($result['errors']) < 50) {
                    $result['errors'][] = '第' . $row['row_number'] . '行: ' . $e->getMessage();
                }
                $logger->warning('Refund object import row failed: ' . $e->getMessage());
            }
        }
    }

    private function finishImportTask(array $result): void
    {
        $summary = sprintf('(匹配%d行/更新%d条/跳过%d/失败%d)', $result['matched_rows'], $result['updated_records'], $result['skipped'], count($result['errors']));
        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '应补应退明细_导入_附件2救助对象名单_';
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

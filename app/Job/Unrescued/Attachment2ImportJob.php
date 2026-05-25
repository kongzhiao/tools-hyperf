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

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $settlementPeriod,
                &$processed,
                $totalCount,
                &$result,
                $logger,
                $filterOptionService,
                $sourceBatch
            ) {
                $processed++;
                $transactionStarted = false;
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
                    $townId = $service->resolveTownId($streetTown);
                    $filterOptionService->saveOption('unrescued', 'priority_identity', $identity, $sourceBatch);

                    $records = UnrescuedRecord::query()
                        ->where('settlement_period', $settlementPeriod)
                        ->where('id_card', $idCard)
                        ->get();

                    if ($records->isEmpty()) {
                        $result['skipped']++;
                        return;
                    }

                    $transactionStarted = true;
                    Db::beginTransaction();
                    foreach ($records as $record) {
                        $data = [
                            'town_id' => $townId,
                            'street_town' => $streetTown ?: null,
                            'village' => $village ?: null,
                            'priority_identity' => $identity ?: null,
                        ];

                        if (($record->name === null || trim((string) $record->name) === '' || trim((string) $record->name) === '--') && $name !== '') {
                            $data['name'] = $name;
                        }

                        if (!$service->shouldKeepWorkflowStatus((string) $record->status)) {
                            $data['status'] = $service->decideStatus((string) $record->calc_reimbursement_amount);
                        }

                        $record->update($data);
                        $result['updated_records']++;
                    }
                    Db::commit();
                    $result['matched_rows']++;
                } catch (\Throwable $e) {
                    if ($transactionStarted) {
                        Db::rollBack();
                    }
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Unrescued attachment2 import row failed: ' . $e->getMessage());
                }

                if ($processed % 100 === 0) {
                    $this->updateProgress($this->uuid, min(($processed / $totalCount) * 100, 99.9));
                }
            });

            $this->finishImportTask($result);
        } catch (\Throwable $e) {
            $this->failTask($e, '附件2导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function finishImportTask(array $result): void
    {
        $summary = sprintf('(匹配%d行/更新%d条/跳过%d/失败%d)', $result['matched_rows'], $result['updated_records'], $result['skipped'], count($result['errors']));
        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '未救助台账_附件2导入_';
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

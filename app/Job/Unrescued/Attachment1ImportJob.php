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

class Attachment1ImportJob extends AbstractJob
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

            $sourceBatch = date('YmdHis');
            $csvReader = new CsvReaderService();
            $totalCount = max($csvReader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

            $csvReader->read($this->tempFile, function (array $row, int $rowIndex) use (
                $service,
                $settlementPeriod,
                $sourceBatch,
                &$processed,
                $totalCount,
                &$result,
                $logger,
                $filterOptionService
            ) {
                $processed++;
                $transactionStarted = false;
                try {
                    $sequenceNo = $service->pickValue($row, ['序号', 'sequence_no']);
                    if ($sequenceNo === '') {
                        $result['skipped']++;
                        return;
                    }

                    $data = [
                        'settlement_period' => $settlementPeriod,
                        'sequence_no' => $sequenceNo,
                        'source_batch' => $sourceBatch,
                        'name' => $service->pickValue($row, ['姓名', 'name']) ?: null,
                        'id_card' => $service->pickValue($row, ['身份证号', '身份证号码', '身份证件号码', '公民身份号码', '身份证', 'id_card']) ?: null,
                        'medical_category' => $service->pickValue($row, ['医疗类别', 'medical_category']) ?: null,
                        'disease_code' => $service->pickValue($row, ['病种编码', 'disease_code']) ?: null,
                        'disease_name' => $service->pickValue($row, ['病种名称', 'disease_name']) ?: null,
                        'cert_location' => $service->pickValue($row, ['认定地', 'cert_location']) ?: null,
                        'hospital_name' => $service->pickValue($row, ['医药机构名称', '医疗机构名称', 'hospital_name']) ?: null,
                        'hospital_code' => $service->pickValue($row, ['医药机构编码', '医疗机构编码', 'hospital_code']) ?: null,
                        'in_out_city' => $service->pickValue($row, ['市（内）外', '市内/市外', 'in_out_city']) ?: null,
                        'admission_date' => $service->parseDate($service->pickValue($row, ['入院时间', '入院日期', 'admission_date'])),
                        'discharge_date' => $service->parseDate($service->pickValue($row, ['出院时间', '出院日期', 'discharge_date'])),
                        'settlement_time' => $service->parseDateTime($service->pickValue($row, ['结算时间', 'settlement_time'])),
                        'total_fee' => $service->parseAmount($service->pickValue($row, ['医疗总费用', '总费用', 'total_fee'])),
                        'policy_fee' => $service->parseAmount($service->pickValue($row, ['医保政策范围费用', '医保政策范围内费用', 'policy_fee'])),
                        'pool_fund_pay' => $service->parseAmount($service->pickValue($row, ['统筹报销金额', 'pool_fund_pay'])),
                        'large_amount_pay' => $service->parseAmount($service->pickValue($row, ['大额报销', '大额报销金额', 'large_amount_pay'])),
                        'serious_illness_pay' => $service->parseAmount($service->pickValue($row, ['大病报销', '大病报销金额', 'serious_illness_pay'])),
                        'used_outpatient_rescue' => $service->parseAmount($service->pickValue($row, ['已使用门诊救助金额', 'used_outpatient_rescue'])),
                        'used_normal_rescue' => $service->parseAmount($service->pickValue($row, ['已使用普通住院救助金额', 'used_normal_rescue'])),
                        'used_major_rescue' => $service->parseAmount($service->pickValue($row, ['已使用重特大疾病救助金额', 'used_major_rescue'])),
                        'used_large_fee_rescue' => $service->parseAmount($service->pickValue($row, ['已使用大额费用住院救助', 'used_large_fee_rescue'])),
                    ];
                    $data['calc_reimbursement_amount'] = $service->calcReimbursementAmount($data);
                    $filterOptionService->saveOption('unrescued', 'medical_category', $data['medical_category'], $sourceBatch);

                    $transactionStarted = true;
                    Db::beginTransaction();
                    $record = UnrescuedRecord::query()
                        ->where('settlement_period', $settlementPeriod)
                        ->where('sequence_no', $sequenceNo)
                        ->first();

                    if ($record) {
                        if (!$service->shouldKeepWorkflowStatus((string) $record->status)) {
                            $data['status'] = $service->decideStatus($data['calc_reimbursement_amount']);
                        }
                        $record->update($data);
                        $result['updated']++;
                    } else {
                        $data['town_id'] = 0;
                        $data['status'] = $service->decideStatus($data['calc_reimbursement_amount']);
                        $data['reimbursement_status'] = UnrescuedRecordService::REIMBURSEMENT_UNPAID;
                        $data['exclude_status'] = UnrescuedRecordService::EXCLUDE_NO;
                        UnrescuedRecord::create($data);
                        $result['created']++;
                    }
                    Db::commit();
                } catch (\Throwable $e) {
                    if ($transactionStarted) {
                        Db::rollBack();
                    }
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Unrescued attachment1 import row failed: ' . $e->getMessage());
                }

                if ($processed % 100 === 0) {
                    $this->updateProgress($this->uuid, min(($processed / $totalCount) * 100, 99.9));
                }
            });

            $this->finishImportTask('未救助台账_附件1导入_', $result);
        } catch (\Throwable $e) {
            $this->failTask($e, '附件1导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function finishImportTask(string $defaultTitle, array $result): void
    {
        $summary = sprintf('(新增%d/更新%d/跳过%d/失败%d)', $result['created'], $result['updated'], $result['skipped'], count($result['errors']));
        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : $defaultTitle;
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

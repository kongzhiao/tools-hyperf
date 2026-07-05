<?php

declare(strict_types=1);

namespace App\Job\Unrescued;

use App\Job\AbstractJob;
use App\Model\Task;
use App\Model\Unrescued\UnrescuedRefundRecord;
use App\Service\CsvReaderService;
use App\Service\Unrescued\UnrescuedRecordService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Logger\LoggerFactory;

class RefundDetailImportJob extends AbstractJob
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
            $total = max($reader->countRows($this->tempFile) - 1, 1);
            $processed = 0;
            $sourceBatch = date('YmdHis');
            $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

            $reader->read($this->tempFile, function (array $row, int $rowIndex) use ($service, $period, $sourceBatch, &$processed, $total, &$result, $logger) {
                $processed++;
                try {
                    $sequenceNo = $service->pickValue($row, ['序号', 'sequence_no']);
                    if ($sequenceNo === '') {
                        $sequenceNo = (string) ($rowIndex + 1);
                    }

                    $data = [
                        'settlement_period' => $period,
                        'sequence_no' => $sequenceNo,
                        'source_batch' => $sourceBatch,
                        'name' => $service->pickValue($row, ['姓名', 'name']) ?: null,
                        'id_card' => $service->pickValue($row, ['身份证号', '身份证号码', '身份证件号码', '公民身份号码', '身份证', 'id_card']) ?: null,
                        'street_town' => $service->pickValue($row, ['镇街', '镇（街）', '镇(街)', '街镇', '乡镇街道', 'street_town']) ?: null,
                        'insurance_place' => $service->pickValue($row, ['参保地', 'insurance_place']) ?: null,
                        'insurance_category' => $service->pickValue($row, ['参加险种', 'insurance_category']) ?: null,
                        'hospital_name' => $service->pickValue($row, ['就诊医疗机构名称', '医药机构名称', '医疗机构名称', 'hospital_name']) ?: null,
                        'medical_category' => $service->pickValue($row, ['医保就诊类别', '医疗类别', 'medical_category']) ?: null,
                        'disease_code' => $service->pickValue($row, ['疾病编码', '病种编码', 'disease_code']) ?: null,
                        'disease_name' => $service->pickValue($row, ['疾病名称', '病种名称', 'disease_name']) ?: null,
                        'admission_date' => $service->parseDate($service->pickValue($row, ['入院时间', '入院日期', 'admission_date'])),
                        'discharge_date' => $service->parseDate($service->pickValue($row, ['出院时间', '出院日期', 'discharge_date'])),
                        'settlement_time' => $service->parseDateTime($service->pickValue($row, ['结算时间', 'settlement_time'])),
                        'total_fee' => $service->parseAmount($service->pickValue($row, ['总费用', '医疗总费用', 'total_fee'])),
                        'policy_fee' => $service->parseAmount($service->pickValue($row, ['医保政策范围内费用', '医保政策范围费用', 'policy_fee'])),
                        'pool_fund_pay' => $service->parseAmount($service->pickValue($row, ['统筹报销金额', 'pool_fund_pay'])),
                        'large_amount_pay' => $service->parseAmount($service->pickValue($row, ['大额报销金额', '大额报销', 'large_amount_pay'])),
                        'serious_illness_pay' => $service->parseAmount($service->pickValue($row, ['大病报销金额', '大病报销', 'serious_illness_pay'])),
                        'medical_assistance_pay' => $service->parseAmount($service->pickValue($row, ['医疗救助金额', 'medical_assistance_pay'])),
                        'yukuaibao_pay' => $service->parseAmount($service->pickValue($row, ['渝快保报销金额', '渝快保支付', 'yukuaibao_pay'])),
                        'personal_account_pay' => $service->parseAmount($service->pickValue($row, ['个人账户支付金额', 'personal_account_pay'])),
                        'personal_cash_pay' => $service->parseAmount($service->pickValue($row, ['个人现金支付金额', 'personal_cash_pay'])),
                    ];
                    $data['calc_reimbursement_amount'] = $service->calcSupplementAmount($data);
                    $data['status'] = $service->decideStatus($data['calc_reimbursement_amount']);

                    $record = UnrescuedRefundRecord::query()
                        ->where('settlement_period', $period)
                        ->where('sequence_no', $sequenceNo)
                        ->first();

                    if ($record) {
                        $record->fill($data);
                        $record->save();
                        $result['updated']++;
                    } else {
                        $data['town_id'] = 0;
                        $data['match_status'] = UnrescuedRecordService::UNMATCHED;
                        $data['exclude_status'] = UnrescuedRecordService::EXCLUDE_NO;
                        UnrescuedRefundRecord::create($data);
                        $result['created']++;
                    }
                } catch (\Throwable $e) {
                    if (count($result['errors']) < 50) {
                        $result['errors'][] = '第' . ($rowIndex + 1) . '行: ' . $e->getMessage();
                    }
                    $logger->warning('Refund detail import row failed: ' . $e->getMessage());
                }

                if ($processed % 500 === 0) {
                    $this->updateProgress($this->uuid, min(($processed / $total) * 100, 99.9));
                }
            });

            $this->finishImportTask($result);
        } catch (\Throwable $e) {
            $logger->error('Refund detail import failed: ' . $e->getMessage(), ['uuid' => $this->uuid]);
            $this->failTask($e, '应补应退明细导入失败');
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function finishImportTask(array $result): void
    {
        $summary = sprintf('(新增%d/更新%d/跳过%d/失败%d)', $result['created'], $result['updated'], $result['skipped'], count($result['errors']));
        $task = Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '应补应退明细_导入_附件4_';
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

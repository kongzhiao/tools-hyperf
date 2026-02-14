<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\YfSettlement;
use App\Service\YfSettlementService;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\CSV\Options;

class YfSettlementExportJob extends AbstractJob
{
    public function handle(): void
    {
        try {
            $this->startTask();

            $container = ApplicationContext::getContainer();
            $settlementService = $container->get(YfSettlementService::class);
            $filters = $this->params['filters'] ?? [];

            $options = new Options();
            $writer = new Writer($options);

            $filename = '优抚联网结算明细_' . date('YmdHis') . '.csv';
            $storagePath = BASE_PATH . '/public/storage/exports/' . $filename;

            if (!is_dir(dirname($storagePath))) {
                mkdir(dirname($storagePath), 0777, true);
            }

            $writer->openToFile($storagePath);

            // 写入 BOM 支持 Excel 打开
            fwrite(fopen($storagePath, 'a'), "\xEF\xBB\xBF");

            // 表头：全量业务维度 (对齐 UI 展示)
            $headers = [
                '姓名',
                '身份证号',
                '优抚类别',
                '医保类别',
                '年度',
                '月份',
                '清算期',
                '费款所属期',
                '就诊地',
                '就诊医疗机构名称',
                '病种名称',
                '入院日期',
                '出院日期',
                '结算日期',
                '医疗费总额',
                '符合医保范围金额',
                '基本医疗基金支出',
                '大病补充医疗保险支出',
                '大额补充医疗保险支出',
                '进入救助金额',
                '医疗救助金额',
                '倾斜救助金额',
                '扶贫济困金额',
                '渝快保支出金额',
                '个人账户支付金额',
                '个人现金支付金额',
                '医保报销和医疗救助金额',
                '符合优抚补助计算金额',
                '年度补助金额',
                '已使用金额',
                '本次补助金额',
                '剩余金额',
                '支付状态',
                '支付时间',
                '导入时间',
                '备注'
            ];
            $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($headers));

            $query = YfSettlement::query();
            $settlementService->applyFilters($query, $filters);

            $total = $query->count();
            $processed = 0;

            $query->chunk(1000, function ($records) use ($writer, &$processed, $total) {
                foreach ($records as $record) {
                    $statusText = '';
                    switch ($record->pay_status) {
                        case -1:
                            $statusText = '不需支付';
                            break;
                        case 0:
                            $statusText = '待支付';
                            break;
                        case 1:
                            $statusText = '已支付';
                            break;
                    }

                    $row = [
                        $record->name,
                        $record->id_card . "\t",
                        $record->category,
                        $record->medical_category,
                        $record->year,
                        $record->month,
                        $record->period_clearing,
                        $record->period_belong,
                        $record->visit_address,
                        $record->hospital_name,
                        $record->disease_name,
                        $record->admission_date ? (string) $record->admission_date : '',
                        $record->discharge_date ? (string) $record->discharge_date : '',
                        $record->settlement_date ? (string) $record->settlement_date : '',
                        round((float) $record->total_amount, 2),
                        round((float) $record->eligible_amount, 2),
                        round((float) $record->fund_pay, 2),
                        round((float) $record->serious_illness_pay, 2),
                        round((float) $record->large_amount_pay, 2),
                        round((float) $record->enter_medical_assistance, 2),
                        round((float) $record->medical_assistance, 2),
                        round((float) $record->slant_assistance, 2),
                        round((float) $record->poverty_assistance, 2),
                        round((float) $record->yukaibao_pay, 2),
                        round((float) $record->personal_account_pay, 2),
                        round((float) $record->personal_cash_pay, 2),
                        round((float) $record->ins_assist_total, 2),
                        round((float) $record->yf_eligible_amount, 2),
                        round((float) $record->annual_quota, 2),
                        round((float) $record->used_amount, 2),
                        round((float) $record->current_subsidy, 2),
                        round((float) $record->remaining_amount, 2),
                        $statusText,
                        $record->pay_at,
                        $record->created_at ? (string) $record->created_at : '',
                        $record->remark
                    ];
                    $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
                    $processed++;
                }

                if ($total > 0) {
                    $this->updateProgress($this->uuid, min(($processed / $total) * 100, 99.9));
                }
            });

            $writer->close();

            $downloadUrl = '/storage/exports/' . $filename;
            $fileSize = round(filesize($storagePath) / 1024 / 1024, 2);
            $this->finishTask($downloadUrl, $fileSize);

        } catch (\Throwable $e) {
            $this->failTask($e);
        }
    }
}

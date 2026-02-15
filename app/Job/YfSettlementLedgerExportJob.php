<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\YfSettlement;
use App\Service\YfSettlementService;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\CSV\Options;

class YfSettlementLedgerExportJob extends AbstractJob
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

            $filename = '优抚联网结算台账_' . date('YmdHis') . '.csv';
            $storagePath = BASE_PATH . '/public/storage/exports/' . $filename;

            if (!is_dir(dirname($storagePath))) {
                mkdir(dirname($storagePath), 0777, true);
            }

            $writer->openToFile($storagePath);
            fwrite(fopen($storagePath, 'a'), "\xEF\xBB\xBF");

            // 台账表头：17个字段 (严格对齐用户需求)
            $headers = [
                '费款所属期',
                '姓名',
                '身份证号',
                '优抚类别',
                '就诊医疗机构名称',
                '入院日期',
                '出院日期',
                '符合医保范围金额',
                '医保报销和医疗救助金额',
                '符合优抚住院医疗补助计算金额',
                '年度补助金额',
                '已使用金额',
                '本次补助金额',
                '剩余金额',
                '支付状态',
                '支付时间',
                '支付备注'
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
                        $record->period_belong,
                        $record->name,
                        $record->id_card . "\t",
                        $record->category,
                        $record->hospital_name,
                        $record->admission_date ? (string) $record->admission_date : '',
                        $record->discharge_date ? (string) $record->discharge_date : '',
                        round((float) $record->eligible_amount, 2),
                        round((float) $record->ins_assist_total, 2),
                        round((float) $record->yf_eligible_amount, 2),
                        round((float) $record->annual_quota, 2),
                        round((float) $record->used_amount, 2),
                        round((float) $record->current_subsidy, 2),
                        round((float) $record->remaining_amount, 2),
                        $statusText,
                        $record->pay_at,
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

            $relPath = str_replace(BASE_PATH . '/', '', $storagePath);
            $fileSize = round(filesize($storagePath) / 1024 / 1024, 2);
            $this->finishTask($relPath, $fileSize);

        } catch (\Throwable $e) {
            $this->failTask($e);
        }
    }
}

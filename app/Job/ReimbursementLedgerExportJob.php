<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\MedReimbursementDetail;
use App\Model\MedMedicalRecord;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Writer\XLSX\Writer;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Style;

/**
 * 医疗救助受理台账导出任务
 */
class ReimbursementLedgerExportJob extends AbstractJob
{
    /**
     * 实现导出逻辑
     */
    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            // 设置脚本执行时间不限
            set_time_limit(0);
            ini_set('memory_limit', '512M');

            $filters = $this->params['filters'] ?? [];
            $uid = $this->params['uid'] ?? 0;

            // 1. 构建查询条件
            $query = MedReimbursementDetail::with(['personInfo']);

            if (!empty($filters['person_id'])) {
                $query->where('person_id', $filters['person_id']);
            }
            if (!empty($filters['medical_record_id'])) {
                $query->whereJsonContains('medical_record_ids', $filters['medical_record_id']);
            }
            if (!empty($filters['medical_record_ids'])) {
                $query->whereJsonContains('medical_record_ids', $filters['medical_record_ids']);
            }
            if (!empty($filters['bank_name'])) {
                $query->where('bank_name', 'like', "%{$filters['bank_name']}%");
            }
            if (!empty($filters['reimbursement_status'])) {
                $query->where('reimbursement_status', $filters['reimbursement_status']);
            }
            if (!empty($filters['account_name'])) {
                $query->where('account_name', 'like', "%{$filters['account_name']}%");
            }

            // 计算总数量（受受理记录数影响进度）
            $totalCount = $query->count();
            if ($totalCount === 0) {
                // 理论上不会到这里，因为控制器已经做了预检查
                throw new \RuntimeException('没有可导出的数据');
            }

            $logger->info("Task {$this->uuid} Reimbursement Ledger Export Start: {$totalCount} records");

            // 2. 准备输出
            $runtimePath = sprintf('%s/public/export/%s/', BASE_PATH, $uid);
            if (!is_dir($runtimePath)) {
                mkdir($runtimePath, 0777, true);
                chmod($runtimePath, 0777);
            }

            $filename = "受理台账_" . date('YmdHis') . "_{$this->uuid}.xlsx";
            $fullPath = $runtimePath . $filename;

            // 3. 使用 OpenSpout 写入 XLSX
            $options = new Options();
            $writer = new Writer($options);
            $writer->openToFile($fullPath);

            // --- 第一张表：受理记录 ---
            $sheet1 = $writer->getCurrentSheet();
            $sheet1->setName('受理记录');

            $headers1 = [
                '受理记录ID',
                '患者姓名',
                '身份证号',
                '银行名称',
                '银行账号',
                '户名',
                '总金额',
                '政策内金额',
                '统筹报销金额',
                '大额报销金额',
                '重疾报销金额',
                '统筹报销比例(%)',
                '大额报销比例(%)',
                '重疾报销比例(%)',
                '受理状态',
                '创建时间',
                '更新时间'
            ];
            $writer->addRow(Row::fromValues($headers1));

            // --- 第二张表：受理明细 (预先创建) ---
            $sheet2 = $writer->addNewSheetAndMakeItCurrent();
            $sheet2->setName('受理明细');
            $headers2 = [
                '受理记录ID',
                '就诊记录ID',
                '患者姓名',
                '身份证号',
                '医院名称',
                '就诊类别',
                '入院时间',
                '出院时间',
                '结算时间',
                '总费用',
                '政策内费用',
                '统筹报销金额',
                '大额报销金额',
                '重疾报销金额',
                '医疗救助金额',
                '渝快保报销金额',
                '处理状态',
                '创建时间'
            ];
            $writer->addRow(Row::fromValues($headers2));

            // 切回第一张表填充数据 (OpenSpout 不支持随机切换 Sheet 写入，所以我们要稍微调整策略)
            // 我们先查出所有数据，或者先写 Sheet2 再写 Sheet1？
            // 更好的做法是：先查询所有需要的 Reimbursement 及其对应的 MedicalRecords。
            // 但如果数据量真的很大，内存会爆。
            // 所以正确的流式做法是：如果必须分 Sheet，可能得先把数据拉过来。
            // 考虑到台账数据虽然多，但通常不会像参保数据（百万级）那样夸张，我们可以采取“分阶段”写入策略，或者接受一定的内存开销。

            // 修正：OpenSpout 的流式写入是按 Sheet 顺序的。一旦切换到 Sheet2，就不能写 Sheet1 了。
            // 所以我们需要：
            // 1. 遍历一次 Query，把数据写到 Sheet1。
            // 2. 再次遍历 Query（或缓存 IDs），把关联的明细写到 Sheet2。

            // 写 Sheet1
            $writer->setCurrentSheet($sheet1);
            $processedCount = 0;
            foreach ($query->orderBy('id', 'desc')->cursor() as $item) {
                $patient = $item->personInfo;
                $writer->addRow(Row::fromValues([
                    $item->id,
                    $patient ? $patient->name : '',
                    $patient ? " " . $patient->id_card : '', // 加空格防止 Excel 科学计数法
                    $item->bank_name,
                    " " . $item->bank_account,
                    $item->account_name,
                    (float) $item->total_amount,
                    (float) $item->policy_covered_amount,
                    (float) $item->pool_reimbursement_amount,
                    (float) $item->large_amount_reimbursement_amount,
                    (float) $item->critical_illness_reimbursement_amount,
                    (float) $item->pool_reimbursement_ratio,
                    (float) $item->large_amount_reimbursement_ratio,
                    (float) $item->critical_illness_reimbursement_ratio,
                    $this->getStatusText($item->reimbursement_status),
                    $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : '',
                    $item->updated_at ? $item->updated_at->format('Y-m-d H:i:s') : '',
                ]));
                $processedCount++;
                $updateInterval = $totalCount < 100 ? 1 : ($totalCount < 1000 ? 100 : 500);
                if ($processedCount % $updateInterval === 0 || $processedCount === $totalCount) {
                    $this->updateProgress($this->uuid, ($processedCount / $totalCount) * 45); // 前 45% 进度
                }
            }

            // 写 Sheet2
            $writer->setCurrentSheet($sheet2);
            $processedCount = 0;
            foreach ($query->orderBy('id', 'desc')->cursor() as $item) {
                $patient = $item->personInfo;
                $medicalRecords = $item->getMedicalRecords();

                if ($medicalRecords->isEmpty()) {
                    $writer->addRow(Row::fromValues([
                        $item->id,
                        '',
                        $patient ? $patient->name : '',
                        $patient ? " " . $patient->id_card : '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        '',
                        ''
                    ]));
                } else {
                    foreach ($medicalRecords as $record) {
                        $writer->addRow(Row::fromValues([
                            $item->id,
                            $record->id,
                            $patient ? $patient->name : '',
                            $patient ? " " . $patient->id_card : '',
                            $record->hospital_name,
                            $record->visit_type,
                            $record->admission_date ? $record->admission_date->format('Y-m-d') : '',
                            $record->discharge_date ? $record->discharge_date->format('Y-m-d') : '',
                            $record->settlement_date ? $record->settlement_date->format('Y-m-d') : '',
                            (float) $record->total_cost,
                            (float) $record->policy_covered_cost,
                            (float) $record->pool_reimbursement_amount,
                            (float) $record->large_amount_reimbursement_amount,
                            (float) $record->critical_illness_reimbursement_amount,
                            (float) $record->medical_assistance_amount,
                            (float) $record->excess_reimbursement_amount,
                            $this->getProcessingStatusText($record->processing_status),
                            $record->created_at ? $record->created_at->format('Y-m-d H:i:s') : '',
                        ]));
                    }
                }
                $processedCount++;
                $updateInterval = $totalCount < 100 ? 1 : ($totalCount < 1000 ? 100 : 500);
                if ($processedCount % $updateInterval === 0 || $processedCount === $totalCount) {
                    $this->updateProgress($this->uuid, 45 + ($processedCount / $totalCount) * 45); // 45%-90% 进度
                }
            }

            $writer->close();

            // 存储相对于 BASE_PATH 的路径
            $relPath = str_replace(BASE_PATH . '/', '', $fullPath);
            $fileSizeMb = round(filesize($fullPath) / (1024 * 1024), 2);
            $this->finishTask($relPath, $fileSizeMb);

            $logger->info("Task {$this->uuid} Export Success: {$fullPath} (Size: {$fileSizeMb}MB)");

        } catch (\Throwable $e) {
            $this->failTask($e, "Task {$this->uuid} Reimbursement Ledger Export Failed");
        }
    }

    private function getStatusText($status)
    {
        $statusMap = [
            'pending' => '未申请',
            'processed' => '已受理',
            'void' => '作废',
        ];
        return $statusMap[$status] ?? $status;
    }

    private function getProcessingStatusText($status)
    {
        $statusMap = [
            'unreimbursed' => '未报销',
            'reimbursed' => '已报销',
            'returned' => '已退回',
        ];
        return $statusMap[$status] ?? $status;
    }
}

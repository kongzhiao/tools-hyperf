<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\MedMedicalRecord;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Common\Entity\Row;

/**
 * 就诊记录导出任务
 */
class MedicalRecordExportJob extends AbstractJob
{
    /**
     * 表头配置
     */
    private const HEADERS = [
        '序号',
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

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            // 设置脚本执行时间不限
            set_time_limit(0);
            ini_set('memory_limit', '256M');

            $filters = $this->params['filters'] ?? [];

            // 构建查询
            $query = MedMedicalRecord::with(['personInfo']);

            // 应用搜索条件
            if (!empty($filters['person_id'])) {
                $query->where('person_id', $filters['person_id']);
            }
            if (!empty($filters['hospital_name'])) {
                $query->where('hospital_name', 'like', "%{$filters['hospital_name']}%");
            }
            if (!empty($filters['visit_type'])) {
                $query->where('visit_type', $filters['visit_type']);
            }
            if (!empty($filters['processing_status'])) {
                $query->where('processing_status', $filters['processing_status']);
            }
            if (!empty($filters['admission_date_start'])) {
                $query->where('admission_date', '>=', $filters['admission_date_start']);
            }
            if (!empty($filters['admission_date_end'])) {
                $query->where('admission_date', '<=', $filters['admission_date_end']);
            }

            // 计算总数量
            $totalCount = $query->count();
            if ($totalCount === 0) {
                // 理论上不会到这里，因为控制器已经做了预检查
                throw new \RuntimeException('没有可导出的数据');
            }

            $logger->info("Task {$this->uuid} MedicalRecord Export Start: {$totalCount} records");

            // 准备输出目录
            $uid = $this->params['uid'] ?? 0;
            $runtimePath = sprintf('%s/public/export/%s/', BASE_PATH, $uid);
            if (!is_dir($runtimePath)) {
                mkdir($runtimePath, 0777, true);
                chmod($runtimePath, 0777);
            }

            // CSV 文件名
            $filename = "救助报销_就诊记录_导出_{$this->uuid}.csv";
            $fullPath = $runtimePath . $filename;

            // 创建 CSV Writer
            $options = new Options();
            $options->FIELD_DELIMITER = ',';
            $options->FIELD_ENCLOSURE = '"';
            $options->SHOULD_ADD_BOM = true;

            $writer = new Writer($options);
            $writer->openToFile($fullPath);

            // 写入表头
            $writer->addRow(Row::fromValues(self::HEADERS));

            // 更新进度
            $this->updateProgress($this->uuid, 5.00);

            $processedCount = 0;
            $index = 0;

            // 使用 cursor() 游标查询，流式写入数据
            foreach ($query->orderBy('id', 'desc')->cursor() as $item) {
                $index++;
                $patient = $item->personInfo;

                $rowData = [
                    $index,
                    $patient ? $patient->name : '',
                    $patient ? $patient->id_card : '',
                    $item->hospital_name ?? '',
                    $item->visit_type ?? '',
                    $item->admission_date ? $item->admission_date->format('Y-m-d') : '',
                    $item->discharge_date ? $item->discharge_date->format('Y-m-d') : '',
                    $item->settlement_date ? $item->settlement_date->format('Y-m-d') : '',
                    (float) $item->total_cost,
                    (float) $item->policy_covered_cost,
                    (float) $item->pool_reimbursement_amount,
                    (float) $item->large_amount_reimbursement_amount,
                    (float) $item->critical_illness_reimbursement_amount,
                    (float) $item->medical_assistance_amount,
                    (float) $item->excess_reimbursement_amount,
                    $this->getProcessingStatusText($item->processing_status),
                    $item->created_at ? $item->created_at->format('Y-m-d H:i:s') : '',
                ];

                $writer->addRow(Row::fromValues($rowData));
                $processedCount++;

                // 动态更新进度：少量数据按条更新，大量数据按1000条更新
                $updateInterval = $totalCount < 100 ? 1 : ($totalCount < 1000 ? 100 : 1000);
                if ($processedCount % $updateInterval === 0 || $processedCount === $totalCount) {
                    $progress = 5 + ($processedCount / $totalCount) * 90;
                    $this->updateProgress($this->uuid, $progress);
                    $logger->debug("Task {$this->uuid} Progress: {$processedCount}/{$totalCount}");
                }
            }

            // 关闭 writer
            $writer->close();

            // 计算文件大小
            $fileSizeMb = round(filesize($fullPath) / (1024 * 1024), 2);

            // 更新任务完成状态
            $this->finishTask("/export/{$uid}/" . $filename, $fileSizeMb);

            $logger->info("Task {$this->uuid} Export Success: {$fullPath} (Size: {$fileSizeMb}MB, Records: {$processedCount})");

        } catch (\Throwable $e) {
            $this->failTask($e, "Task {$this->uuid} Export Failed");
        }
    }

    /**
     * 转换处理状态为中文
     */
    private function getProcessingStatusText(?string $status): string
    {
        $statusMap = [
            'unreimbursed' => '未报销',
            'reimbursed' => '已报销',
            'returned' => '已退回',
        ];
        return $statusMap[$status] ?? $status ?? '';
    }
}

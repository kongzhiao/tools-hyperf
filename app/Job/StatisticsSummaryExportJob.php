<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\StatisticsData;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Common\Entity\Row;

/**
 * 统计明细导出任务
 * 
 * 使用 OpenSpout CSV 流式写入，支持百万级数据导出不 OOM。
 * 配合 Eloquent cursor() 游标查询，内存占用恒定。
 * 
 * CSV 格式比 XLSX 快 5-10 倍。
 */
class StatisticsSummaryExportJob extends AbstractJob
{
    /**
     * @var array
     */
    public $params;

    /**
     * @var string
     */
    public $uuid;

    /**
     * 表头配置（增加"类型"列）
     */
    private const HEADERS = [
        '类型',
        '项目代码',
        '医保分类',
        '清算期',
        '费款所属期',
        '结算id',
        '认定地',
        '镇街',
        '参保地',
        '参保类别',
        '身份证号',
        '姓名',
        '救助身份',
        '就诊地',
        '就诊医疗机构名称',
        '医保就诊类别',
        '医疗救助类别',
        '病种编码',
        '病种名称',
        '入院日期',
        '出院日期',
        '结算日期',
        '费用总额',
        '符合医保报销金额',
        '基本医疗保险报销金额',
        '大病报销金额',
        '大额报销金额',
        '进入医疗救助金额',
        '医疗救助',
        '倾斜救助',
        '扶贫济困金额（元）',
        '渝快保支出金额（元）',
        '个人账户支付金额（元）',
        '个人现金支付金额（元）'
    ];

    public function __construct(array $params, string $uuid)
    {
        $this->params = $params;
        $this->uuid = $uuid;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            // 标记任务为执行中
            $this->updateTask($this->uuid, [
                'status' => \App\Model\Task::STATUS_RUNNING,
                'progress' => 0.00
            ]);

            // 设置脚本执行时间不限
            set_time_limit(0);
            // 使用流式写入，内存需求大幅降低
            ini_set('memory_limit', '256M');

            $projectIds = $this->params['project_ids'] ?? [];
            if (empty($projectIds)) {
                $this->updateProgress($this->uuid, 100);
                return;
            }

            // 计算总数量用于进度展示
            $totalCount = StatisticsData::whereIn('project_id', $projectIds)->count();
            if ($totalCount === 0) {
                $this->updateProgress($this->uuid, 100);
                return;
            }

            $logger->info("Task {$this->uuid} Export Start: {$totalCount} records to export (CSV mode)");

            // 准备输出目录（使用 public 目录，容器重建后可持久化）
            $runtimePath = sprintf('%s/public/export/%s/', BASE_PATH, $this->params['uid']);
            if (!is_dir($runtimePath)) {
                mkdir($runtimePath, 0777, true);
                chmod($runtimePath, 0777);  // 确保 Linux 环境下权限正确
            }

            // CSV 文件名
            $filename = '统计明细_导出_'. $this->uuid . '_' . date('Y-m-d_H-i-s') . '.csv';
            $fullPath = $runtimePath . $filename;

            // 创建 CSV Writer
            $options = new Options();
            $options->FIELD_DELIMITER = ',';
            $options->FIELD_ENCLOSURE = '"';
            $options->SHOULD_ADD_BOM = true; // 添加 BOM 以支持 Excel 打开中文

            $writer = new Writer($options);
            $writer->openToFile($fullPath);

            // 写入表头
            $writer->addRow(Row::fromValues(self::HEADERS));

            // 更新任务状态为执行中
            $this->updateTask($this->uuid, [
                'progress' => 5.00,
                'status' => \App\Model\Task::STATUS_RUNNING
            ]);

            $processedCount = 0;

            // 使用 cursor() 游标查询，流式写入数据
            $query = StatisticsData::query()->whereIn('project_id', $projectIds)->with(['project']);

            foreach ($query->cursor() as $item) {
                $rowData = [
                    $item->import_type ?? '',  // 类型列，直接使用数据库字段
                    $item->project->code ?? '',
                    $item->medical_category ?? '',
                    $item->settlement_period ?? '',
                    $item->fee_period ?? '',
                    $item->settlement_id ?? '',
                    $item->certification_place ?? '',
                    $item->street_town ?? '',
                    $item->insurance_place ?? '',
                    $item->insurance_category ?? '',
                    $item->id_number ?? '',
                    $item->name ?? '',
                    $item->assistance_identity ?? '',
                    $item->visit_place ?? '',
                    $item->medical_institution ?? '',
                    $item->medical_visit_category ?? '',
                    $item->medical_assistance_category ?? '',
                    $item->disease_code ?? '',
                    $item->disease_name ?? '',
                    $item->admission_date ?? '',
                    $item->discharge_date ?? '',
                    $item->settlement_date ?? '',
                    $item->total_cost ?? 0,
                    $item->eligible_reimbursement ?? 0,
                    $item->basic_medical_reimbursement ?? 0,
                    $item->serious_illness_reimbursement ?? 0,
                    $item->large_amount_reimbursement ?? 0,
                    $item->medical_assistance_amount ?? 0,
                    $item->medical_assistance ?? 0,
                    $item->tilt_assistance ?? 0,
                    $item->poverty_relief_amount ?? 0,
                    $item->yukuaibao_amount ?? 0,
                    $item->personal_account_amount ?? 0,
                    $item->personal_cash_amount ?? 0,
                ];

                $writer->addRow(Row::fromValues($rowData));
                $processedCount++;

                // 每处理 1000 条更新一次进度
                if ($processedCount % 1000 === 0) {
                    $progress = 5 + ($processedCount / $totalCount) * 90;
                    $this->updateProgress($this->uuid, $progress);
                    $logger->debug("Task {$this->uuid} Progress: {$processedCount}/{$totalCount}");
                }
            }

            // 关闭 writer，完成文件写入
            $writer->close();

            // 计算文件大小
            $fileSizeMb = round(filesize($fullPath) / (1024 * 1024), 2);

            // 更新任务完成状态
            $this->finalizeTask($fullPath, $filename, $fileSizeMb);

            $logger->info("Task {$this->uuid} Export Success: {$fullPath} (Size: {$fileSizeMb}MB, Records: {$processedCount})");

        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} Export Failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // 标记任务执行失败（status=-2）
            $this->updateTask($this->uuid, [
                'status' => \App\Model\Task::STATUS_FAILED
            ]);
            // 释放 Redis 锁
            $this->releaseLock();
            // 不再抛出异常，避免队列重试
        }
    }

    /**
     * Finalize task after successful export.
     */
    protected function finalizeTask(string $fullPath, string $filename, float $fileSizeMb): void
    {
        $uid = $this->params['uid'] ?? 0;
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'file_url' => "/export/{$uid}/" . $filename,
            'url_at' => date('Y-m-d H:i:s'),
            'file_size' => $fileSizeMb,
            'status' => \App\Model\Task::STATUS_COMPLETED
        ]);
        // 释放 Redis 锁
        $this->releaseLock();
    }
}
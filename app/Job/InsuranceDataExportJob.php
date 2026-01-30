<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\InsuranceData;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Common\Entity\Row;

/**
 * 参保数据匹配结果导出任务
 * 
 * 使用 OpenSpout CSV 流式写入，支持大数据量导出不 OOM。
 */
class InsuranceDataExportJob extends AbstractJob
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
     * 表头配置
     */
    private const HEADERS = [
        '序号',
        '街道乡镇',
        '姓名',
        '身份证件号码',
        '代缴类别',
        '代缴金额',
        '档次',
        '档次匹配状态',
        '医疗救助匹配状态',
        '认定区匹配状态',
        '匹配状态'
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
            ini_set('memory_limit', '256M');

            $filters = $this->params['filters'] ?? [];

            // 构建查询
            $query = InsuranceData::query();

            // 应用搜索条件
            if (!empty($filters['year'])) {
                $query->where('year', $filters['year']);
            }
            if (!empty($filters['street_town'])) {
                $query->where('street_town', 'like', "%{$filters['street_town']}%");
            }
            if (!empty($filters['name'])) {
                $query->where('name', 'like', "%{$filters['name']}%");
            }
            if (!empty($filters['id_number'])) {
                $query->where('id_number', 'like', "%{$filters['id_number']}%");
            }
            if (!empty($filters['payment_category'])) {
                $query->where('payment_category', $filters['payment_category']);
            }
            if (!empty($filters['level'])) {
                $query->where('level', $filters['level']);
            }
            if (!empty($filters['medical_assistance_category'])) {
                $query->where('medical_assistance_category', $filters['medical_assistance_category']);
            }
            if (!empty($filters['level_match_status'])) {
                $query->where('level_match_status', $filters['level_match_status']);
            }
            if (!empty($filters['assistance_identity_match_status'])) {
                $query->where('assistance_identity_match_status', $filters['assistance_identity_match_status']);
            }
            if (!empty($filters['street_town_match_status'])) {
                $query->where('street_town_match_status', $filters['street_town_match_status']);
            }
            if (!empty($filters['match_status'])) {
                $query->where('match_status', $filters['match_status']);
            }

            // 计算总数量
            $totalCount = $query->count();
            if ($totalCount === 0) {
                $this->updateProgress($this->uuid, 100);
                return;
            }

            $logger->info("Task {$this->uuid} InsuranceData Export Start: {$totalCount} records");

            // 准备输出目录
            $uid = $this->params['uid'] ?? 0;
            $runtimePath = sprintf('%s/public/export/%s/', BASE_PATH, $uid);
            if (!is_dir($runtimePath)) {
                mkdir($runtimePath, 0777, true);
                chmod($runtimePath, 0777);
            }

            // CSV 文件名
            $year = $filters['year'] ?? date('Y');
            $filename = "参保数据_导出_匹配结果_{$year}年_{$this->uuid}.csv";
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
            $this->updateTask($this->uuid, [
                'progress' => 5.00,
                'status' => \App\Model\Task::STATUS_RUNNING
            ]);

            $processedCount = 0;
            $index = 0;

            // 使用 cursor() 游标查询，流式写入数据
            foreach ($query->orderBy('id', 'asc')->cursor() as $item) {
                $index++;
                $rowData = [
                    $index,
                    $item->street_town ?? '',
                    $item->name ?? '',
                    $item->id_number ?? '',
                    $item->payment_category ?? '',
                    $item->payment_amount ?? 0,
                    $item->level ?? '',
                    $this->convertMatchStatus($item->level_match_status),
                    $this->convertMatchStatus($item->assistance_identity_match_status),
                    $this->convertMatchStatus($item->street_town_match_status),
                    $this->convertDataStatus($item->match_status),
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

            // 关闭 writer
            $writer->close();

            // 计算文件大小
            $fileSizeMb = round(filesize($fullPath) / (1024 * 1024), 2);

            // 更新任务完成状态
            $this->updateTask($this->uuid, [
                'progress' => 100.00,
                'file_url' => "/export/{$uid}/" . $filename,
                'url_at' => date('Y-m-d H:i:s'),
                'file_size' => $fileSizeMb,
                'status' => \App\Model\Task::STATUS_COMPLETED
            ]);

            // 释放锁
            $this->releaseLock();

            $logger->info("Task {$this->uuid} Export Success: {$fullPath} (Size: {$fileSizeMb}MB, Records: {$processedCount})");

        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} Export Failed: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            $this->updateTask($this->uuid, [
                'status' => \App\Model\Task::STATUS_FAILED
            ]);
            $this->releaseLock();
        }
    }

    /**
     * 转换匹配状态为中文
     */
    private function convertMatchStatus(?string $status): string
    {
        if ($status === 'matched') {
            return '已匹配';
        } elseif ($status === 'unmatched') {
            return '未匹配';
        }
        return '待匹配';
    }

    /**
     * 转换数据状态为中文
     */
    private function convertDataStatus(?string $status): string
    {
        if ($status === 'matched') {
            return '正常数据';
        } elseif ($status === 'unmatched') {
            return '疑点数据';
        }
        return '待匹配';
    }
}

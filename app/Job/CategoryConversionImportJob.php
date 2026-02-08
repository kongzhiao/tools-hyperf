<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\CategoryConversion;
use App\Service\CsvReaderService;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;

/**
 * 类别转换导入任务
 * 使用 CsvReaderService 流式读取 CSV 文件
 */
class CategoryConversionImportJob extends AbstractJob
{
    public string $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        parent::__construct($params, $uuid);
        $this->tempFile = $tempFile;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            if (!file_exists($this->tempFile)) {
                throw new \Exception("导入文件不存在: " . $this->tempFile);
            }

            $csvReader = new CsvReaderService();
            $result = [
                'imported' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => []
            ];

            // 表头字段映射（支持多种表头名称）
            $mappings = [
                'tax_standard' => ['税务代缴数据口径', '税务口径', '税务代缴口径'],
                'medical_export_standard' => ['医保数据导出对象口径', '医保口径', '医保导出口径'],
                'national_dict_name' => ['国家字典值名称', '国家字典', '字典名称']
            ];

            $totalRows = $csvReader->countRows($this->tempFile) - 1; // 减去表头
            $processedRows = 0;

            // 逐行处理数据
            $csvReader->read(
                $this->tempFile,
                function ($rowData, $rowIndex, $headers) use (&$result, &$processedRows, $totalRows, $mappings, $logger) {
                    try {
                        // 提取数据
                        $data = $this->extractData($rowData, $mappings);

                        // 验证必填字段
                        if (empty($data['tax_standard'])) {
                            throw new \Exception('税务代缴数据口径不能为空');
                        }

                        // 验证至少有一个映射字段
                        if (empty($data['medical_export_standard']) && empty($data['national_dict_name'])) {
                            throw new \Exception('医保数据导出对象口径和国家字典值名称至少填写一项');
                        }

                        // 检查是否已存在（根据 tax_standard + medical_export_standard + national_dict_name 去重）
                        $existing = CategoryConversion::where('tax_standard', $data['tax_standard'])
                            ->where('medical_export_standard', $data['medical_export_standard'] ?? '')
                            ->where('national_dict_name', $data['national_dict_name'] ?? '')
                            ->first();

                        if ($existing) {
                            // 记录已存在，跳过
                            $result['skipped']++;
                        } else {
                            // 创建新记录
                            CategoryConversion::create([
                                'tax_standard' => $data['tax_standard'],
                                'medical_export_standard' => $data['medical_export_standard'] ?? null,
                                'national_dict_name' => $data['national_dict_name'] ?? null
                            ]);
                            $result['imported']++;
                        }

                    } catch (\Throwable $e) {
                        $result['errors'][] = "第" . ($rowIndex + 1) . "行：" . $e->getMessage();
                        $logger->warning("Row {$rowIndex} import failed: " . $e->getMessage());
                    }

                    $processedRows++;

                    // 更新进度
                    if ($processedRows % 50 === 0 && $totalRows > 0) {
                        $progress = ($processedRows / $totalRows) * 100;
                        $this->updateProgress($this->uuid, min($progress, 99));
                    }
                },
                true,
                null
            );

            // 清理临时文件
            if (file_exists($this->tempFile)) {
                unlink($this->tempFile);
            }

            // 计算成功率
            $total = $result['imported'] + $result['updated'] + $result['skipped'];
            $successRate = $total > 0
                ? round(($result['imported'] + $result['updated']) / $total * 100, 2)
                : 0;

            $result['summary'] = [
                'total' => $total,
                'success_rate' => $successRate
            ];

            $this->finalizeImportTask($result);
            $logger->info("Task {$this->uuid} CategoryConversion Import Success.", $result);

        } catch (\Throwable $e) {
            $logger->error("Task {$this->uuid} CategoryConversion Import Failed: " . $e->getMessage());
            $this->failTask($e);
            throw $e;
        }
    }

    /**
     * 提取数据字段
     */
    private function extractData(array $rowData, array $mappings): array
    {
        $data = [];
        foreach ($mappings as $field => $possibleHeaders) {
            foreach ($possibleHeaders as $header) {
                if (isset($rowData[$header]) && $rowData[$header] !== '') {
                    $data[$field] = trim((string) $rowData[$header]);
                    break;
                }
            }
        }
        return $data;
    }

    /**
     * 完成导入任务
     */
    protected function finalizeImportTask(array $result): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'url_at' => date('Y-m-d H:i:s'),
            'status' => \App\Model\Task::STATUS_COMPLETED
        ]);
    }
}

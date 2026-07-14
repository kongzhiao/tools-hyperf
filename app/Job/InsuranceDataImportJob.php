<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\InsuranceData;
use App\Model\InsuranceYear;
use App\Model\Task;
use App\Service\CsvReaderService;
use App\Service\InsuranceLevelConfigCache;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;

/**
 * 参保数据导入异步任务
 */
class InsuranceDataImportJob extends AbstractJob
{
    public string $filePath;
    public int $year;
    public string $importType;
    public array $columnMap;
    public int $headerRow;

    public function __construct(array $params, string $uuid, string $filePath, int $year, string $importType, array $columnMap, int $headerRow = 1)
    {
        parent::__construct($params, $uuid);
        $this->filePath = $filePath;
        $this->year = $year;
        $this->importType = $importType;
        $this->columnMap = $columnMap;
        $this->headerRow = $headerRow;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            // 核心修复：在长连接环境（Swoole）中强制清理过期缓存，确保最新配置生效
            \App\Service\CategoryConversionCache::clear();
            \App\Service\InsuranceLevelConfigCache::clearAllCache();

            if (!file_exists($this->filePath)) {
                throw new \Exception("导入文件不存在: " . $this->filePath);
            }

            // 初始化缓存
            InsuranceLevelConfigCache::loadConfigsForYear($this->year);

            // 如果是全量导入，先删除该年份的所有数据
            if ($this->importType === 'full') {
                InsuranceData::where('year', $this->year)->delete();
                $logger->info("Year {$this->year} data cleared for full import.");
            }

            $csvReader = new CsvReaderService();
            $totalRows = max(0, $csvReader->countRows($this->filePath) - $this->headerRow);

            $result = [
                'imported_count' => 0,
                'skipped_count' => 0,
                'error_rows' => [],
                'total_rows' => $totalRows
            ];

            $batchSize = 500;
            $validData = [];
            $processedCount = 0;
            $levelMatchingTime = 0;

            // 获取增量模式下的已存在身份证号（简单去重方案，大数据量下建议结合索引分批处理）
            $duplicateIds = [];
            if ($this->importType === 'increment') {
                $duplicateIds = InsuranceData::where('year', $this->year)
                    ->pluck('id_number')
                    ->flip()
                    ->toArray();
            }

            $currentRowIdx = 0;
            $csvReader->read($this->filePath, function ($row) use (&$result, &$validData, &$processedCount, &$currentRowIdx, &$levelMatchingTime, $batchSize, $duplicateIds, $logger) {
                $currentRowIdx++;
                if ($currentRowIdx <= $this->headerRow) {
                    return;
                }

                try {
                    $idCard = isset($this->columnMap['id_number']) ? trim((string) ($row[$this->columnMap['id_number']] ?? '')) : null;
                    $paymentCategory = trim((string) ($row[$this->columnMap['payment_category']] ?? ''));

                    // 执行标准口径转换映射 (统一使用 Service 缓存加速)
                    $conversion = \App\Service\CategoryConversionCache::findByAnyValue($paymentCategory);
                    if ($conversion) {
                        $paymentCategory = $conversion->tax_standard;
                    }

                    if (empty($idCard)) {
                        $result['skipped_count']++;
                        $result['error_rows'][] = ['row' => $currentRowIdx, 'reason' => '身份证号为空'];
                        return;
                    }

                    if ($this->importType === 'increment' && isset($duplicateIds[$idCard])) {
                        $result['skipped_count']++;
                        return;
                    }

                    $data = [
                        'year' => $this->year,
                        'id_type' => '居民身份证'
                    ];

                    foreach ($this->columnMap as $field => $colIdx) {
                        if ($colIdx !== null) {
                            $cellValue = $row[$colIdx] ?? '';
                            if ($field === 'payment_amount') {
                                $data[$field] = is_numeric($cellValue) ? floatval($cellValue) : 0;
                            } elseif ($field === 'payment_category') {
                                // 核心修复：使用前面已经映射过的标准化值，防止被原始 CSV 值覆盖
                                $data[$field] = $paymentCategory;
                            } else {
                                $data[$field] = trim((string) $cellValue);
                            }
                        }
                    }

                    // 必填验证
                    $requiredFields = [
                        'name' => '姓名',
                        'id_number' => '身份证号',
                        'street_town' => '街道乡镇',
                        'payment_category' => '代缴类别',
                        'payment_amount' => '代缴金额'
                    ];
                    foreach ($requiredFields as $field => $label) {
                        $val = $data[$field] ?? '';
                        if ($val === '' || $val === null) {
                            $result['skipped_count']++;
                            $result['error_rows'][] = ['row' => $currentRowIdx, 'reason' => "必填字段 {$label} 不能为空"];
                            return;
                        }
                    }

                    // 档次匹配
                    $levelMatchingStart = microtime(true);
                    $levelConfigs = InsuranceLevelConfigCache::findMatchingConfigs(
                        $this->year,
                        $data['payment_category'],
                        $data['payment_amount']
                    );
                    $levelMatchingTime += microtime(true) - $levelMatchingStart;

                    if ($levelConfigs->count() === 1) {
                        $levelConfig = $levelConfigs->first();
                        $data['level'] = $levelConfig->level;
                        $data['level_match_status'] = 'matched';
                        $data['personal_amount'] = $levelConfig->personal_amount;
                    } else {
                        $data['level'] = '';
                        $data['level_match_status'] = 'unmatched';
                    }

                    $validData[] = $data;

                    // 达到批次大小，执行插入
                    if (count($validData) >= $batchSize) {
                        $this->flushBatch($validData, $result);
                    }

                } catch (\Exception $e) {
                    $result['skipped_count']++;
                    $result['error_rows'][] = ['row' => $currentRowIdx, 'reason' => '处理异常：' . $e->getMessage()];
                }

                $processedCount++;
                if ($processedCount % 100 === 0 && $result['total_rows'] > 0) {
                    $this->updateProgress($this->uuid, min(($processedCount / $result['total_rows']) * 100, 99.9));
                }
            }, false);

            // 处理剩余数据
            if (!empty($validData)) {
                $this->flushBatch($validData, $result);
            }

            $this->finishImportTask($result);

            // 清理临时文件（确保在任务状态更新成功后再清理）
            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }
            $logger->info("InsuranceData Import Job Finished.", ['uuid' => $this->uuid, 'imported' => $result['imported_count']]);

        } catch (\Throwable $e) {
            $logger->error("InsuranceData Import Job Failed: " . $e->getMessage());
            $this->failTask($e);
            throw $e;
        }
    }

    protected function flushBatch(array &$validData, array &$result): void
    {
        foreach ($validData as $data) {
            try {
                // 性能优化：在内存中预计算综合状态，减少后续 DB 交互
                $tempInstance = new InsuranceData($data);
                $data['match_status'] = $tempInstance->calculateOverallMatchStatus();

                // 使用 updateOrCreate 实现 Upsert，由于 match_status 已预填，一次保存即可
                InsuranceData::updateOrCreate(
                    [
                        'year' => $data['year'],
                        'id_number' => $data['id_number']
                    ],
                    $data
                );

                $result['imported_count']++;
            } catch (\Exception $e) {
                $result['skipped_count']++;
                $result['error_rows'][] = [
                    'reason' => '写入或更新失败：' . $e->getMessage(),
                    'id_number' => $data['id_number'] ?? 'unknown'
                ];
            }
        }
        $validData = []; // 清空缓存
    }

    protected function finishImportTask(array $result): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'status' => Task::STATUS_COMPLETED,
            'url_at' => date('Y-m-d H:i:s'),
        ]);
        $this->releaseLock();
    }
}

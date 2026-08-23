<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\InsuranceData;
use App\Model\Task;
use App\Service\CsvReaderService;
use App\Service\InsuranceLevelConfigCache;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;

/**
 * 参保数据匹配更新异步任务（用于区划匹配、档次匹配等）
 */
class InsuranceDataUpdateJob extends AbstractJob
{
    public string $filePath;
    public int $year;
    public array $columnMap;
    public string $type; // 'street_town' or 'level_match'

    public function __construct(array $params, string $uuid, string $filePath, int $year, array $columnMap, string $type)
    {
        parent::__construct($params, $uuid);
        $this->filePath = $filePath;
        $this->year = $year;
        $this->columnMap = $columnMap;
        $this->type = $type;
    }

    public function handle()
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            // 核心修复：强制清理过期缓存，防止 Swoole 常驻内存导致配置不更新
            \App\Service\CategoryConversionCache::clear();
            \App\Service\InsuranceLevelConfigCache::clearAllCache();

            if (!file_exists($this->filePath)) {
                throw new \Exception("文件不存在: " . $this->filePath);
            }

            $csvReader = new CsvReaderService();
            $totalRows = $csvReader->countRows($this->filePath) - 1;

            $result = [
                'success_count' => 0,
                'fail_count' => 0,
                'errors' => [],
                'total_rows' => $totalRows
            ];

            $processedCount = 0;
            $currentRowIdx = 0;

            $csvReader->read($this->filePath, function ($row) use (&$result, &$processedCount, &$currentRowIdx, $logger) {
                $currentRowIdx++;
                if ($currentRowIdx === 1)
                    return; // 跳过表头

                try {
                    $idNumber = trim((string) ($row[$this->columnMap['id_number']] ?? ''));
                    if (empty($idNumber)) {
                        $result['fail_count']++;
                        return;
                    }

                    $insuranceData = InsuranceData::query()->whereBlind('id_number', $idNumber)
                        ->where('year', $this->year)
                        ->first();

                    if (!$insuranceData) {
                        $result['fail_count']++;
                        $result['errors'][] = "行 {$currentRowIdx}: 未找到身份证号为 {$idNumber} 的参保数据 (年份: {$this->year})";
                        return;
                    }

                    if ($this->type === 'street_town') {
                        $assistanceIdentity = trim((string) ($row[$this->columnMap['assistance_identity']] ?? ''));
                        $streetTownName = trim((string) ($row[$this->columnMap['street_town_name']] ?? ''));

                        // 1. 救助身份匹配与映射逻辑 (使用内存缓存，消除 DB 查询)
                        $conversion = \App\Service\CategoryConversionCache::findByAnyValue($assistanceIdentity);
                        $isIdentityMatched = ($conversion !== null);

                        // 若命中映射记录，则将身份字段替换为标准口径 (tax_standard)
                        $storedIdentity = $isIdentityMatched ? $conversion->tax_standard : $assistanceIdentity;

                        // 2. 认定区不再校验是否为“江津区”，统一按已匹配处理
                        // $isStreetMatched = ($streetTownName === '江津区');
                        $isStreetMatched = true;

                        $insuranceData->assistance_identity = $storedIdentity;
                        $insuranceData->assistance_identity_match_status = $isIdentityMatched ? 'matched' : 'unmatched';
                        $insuranceData->street_town_name = $streetTownName;
                        $insuranceData->street_town_match_status = $isStreetMatched ? 'matched' : 'unmatched';

                        // 3. 性能优化：预计算总状态后执行单次保存
                        $insuranceData->match_status = $insuranceData->calculateOverallMatchStatus();
                        $insuranceData->save();

                        $result['success_count']++;

                    } elseif ($this->type === 'level_match') {
                        $personalPayment = trim((string) ($row[$this->columnMap['personal_amount']] ?? ''));
                        $amount = is_numeric($personalPayment) ? floatval($personalPayment) : 0;

                        // 基于年份、代缴类别、实缴金额反查档次 (使用内存缓存)
                        $matchedConfigs = InsuranceLevelConfigCache::findMatchingConfigsByPersonalAmount(
                            $this->year,
                            $insuranceData->payment_category,
                            $amount
                        );

                        if ($matchedConfigs->count() === 1) {
                            $config = $matchedConfigs->first();
                            $insuranceData->level = $config->level;
                            $insuranceData->personal_amount = $amount;
                            $insuranceData->level_match_status = 'matched';
                        } else {
                            // 匹配失败（找不到或多条，保持现状或置为不匹配）
                            $insuranceData->level_match_status = 'unmatched';
                        }

                        // 3. 性能优化：预计算总状态后执行单次保存
                        $insuranceData->match_status = $insuranceData->calculateOverallMatchStatus();
                        $insuranceData->save();

                        $result['success_count']++;
                    }

                } catch (\Exception $e) {
                    $result['fail_count']++;
                    $result['errors'][] = "行 {$currentRowIdx} 处理失败: " . $e->getMessage();
                }

                $processedCount++;
                if ($processedCount % 100 === 0 && $result['total_rows'] > 0) {
                    $this->updateProgress($this->uuid, min(($processedCount / $result['total_rows']) * 100, 99.9));
                }
            }, false);

            $this->finishMatchTask($result);

            if (file_exists($this->filePath)) {
                unlink($this->filePath);
            }
            $logger->info("InsuranceData Update Job Finished.", ['uuid' => $this->uuid, 'type' => $this->type]);

        } catch (\Throwable $e) {
            $logger->error("InsuranceData Update Job Failed: " . $e->getMessage());
            $this->failTask($e);
            throw $e;
        }
    }

    protected function finishMatchTask(array $result): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'status' => Task::STATUS_COMPLETED,
            'url_at' => date('Y-m-d H:i:s'),
        ]);
        $this->releaseLock();
    }
}

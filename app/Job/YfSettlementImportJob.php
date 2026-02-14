<?php

declare(strict_types=1);

namespace App\Job;

use App\Model\YfSettlement;
use App\Model\Task;
use App\Service\YfSettlementService;
use App\Service\CsvReaderService;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Context\ApplicationContext;
use Hyperf\DbConnection\Db;
use Hyperf\Logger\LoggerFactory;

class YfSettlementImportJob extends AbstractJob
{
    public $tempFile;

    public function __construct(array $params, string $uuid, string $tempFile)
    {
        parent::__construct($params, $uuid);
        $this->tempFile = $tempFile;
    }

    public function handle(): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');

        try {
            $this->startTask();

            if (!file_exists($this->tempFile)) {
                throw new \Exception("导入文件不存在: " . $this->tempFile);
            }

            $csvReader = new CsvReaderService();
            $settlementService = $container->get(YfSettlementService::class);

            // 缓存配额数据，减少数据库查询
            $quotaCache = [];

            $result = [
                'total' => 0,
                'imported' => 0,
                'errors' => []
            ];

            // 字段映射 (增加更多别名以提高鲁棒性，对接新表结构)
            $mappings = [
                'name' => ['姓名', '优抚对象', '对象姓名'],
                'id_card' => ['身份证号', '身份证', '身份号码', '公民身份号码'],
                'category' => ['优抚类别', '对象类别', '优抚对象类别'],
                'medical_category' => ['医保类别', '人员类别', '参保类别'],
                'period_clearing' => ['医保局清算期', '清算期'],
                'period_belong' => ['费款所属期', '账期', '账期年月', '所属月份'],
                'visit_address' => ['就诊地', '就诊地点'],
                'hospital_name' => ['就诊医疗机构名称', '医疗机构', '医院名称', '就诊机构'],
                'disease_name' => ['病种名称', '疾病名称'],
                'admission_date' => ['入院日期', '就诊日期', '入院时间'],
                'discharge_date' => ['出院日期', '离院日期', '出院时间'],
                'settlement_date' => ['结算日期', '结算时间'],
                'total_amount' => ['医疗费总额（元）', '医疗费总额', '总费用', '费用总额'],
                'eligible_amount' => ['符合医保范围金额（元）', '符合医保范围金额', '符合范围金额', '合规金额'],
                'fund_pay' => ['基本医疗基金支出（元）', '基本医疗基金支出', '基本医疗基金', '基金支付'],
                'serious_illness_pay' => ['大病补充医疗保险支出（元）', '大病补充医疗保险支出', '大病支出'],
                'large_amount_pay' => ['大额补充医疗保险支出（元）', '大额补充医疗保险支出', '大额支出'],
                'enter_medical_assistance' => ['进入医疗救助金额（元）', '进入医疗救助金额'],
                'medical_assistance' => ['医疗救助金额（元）', '医疗救助金额', '医疗救助', '救助金额'],
                'slant_assistance' => ['倾斜救助金额（元）', '倾斜救助金额', '倾斜补助'],
                'poverty_assistance' => ['扶贫济困金额（元）', '扶贫济困金额', '扶贫救助'],
                'yukaibao_pay' => ['渝快保支出金额（元）', '渝快保支出金额', '渝快保'],
                'personal_account_pay' => ['个人账户支付金额（元）', '个人账户支付金额', '个账支付'],
                'personal_cash_pay' => ['个人现金支付金额（元）', '个人现金支付金额', '现金支付'],
            ];
            // 备注: ins_assist_total, yf_eligible_amount 等由 Service 自动计算，无需映射

            $totalCount = $csvReader->countRows($this->tempFile) - 1;
            $processed = 0;

            $csvReader->read(
                $this->tempFile,
                function ($rowData, $rowIndex) use (&$result, &$processed, $totalCount, $mappings, $settlementService, $logger, &$quotaCache) {
                    $result['total']++;
                    try {
                        Db::beginTransaction();

                        $modelData = [];
                        foreach ($mappings as $modelKey => $csvHeaders) {
                            $val = null;
                            foreach ($csvHeaders as $header) {
                                if (isset($rowData[$header]) && $rowData[$header] !== '') {
                                    $val = $rowData[$header];
                                    break;
                                }
                            }

                            if ($val !== null) {
                                if (str_ends_with($modelKey, '_date')) {
                                    $modelData[$modelKey] = $this->parseDate((string) $val);
                                } elseif (str_ends_with($modelKey, '_amount') || str_ends_with($modelKey, '_pay') || str_ends_with($modelKey, '_assistance')) {
                                    $modelData[$modelKey] = CsvReaderService::parseAmount($val);
                                } elseif ($modelKey === 'category') {
                                    $modelData[$modelKey] = \App\Service\YfSettlementService::normalizeCategory((string) $val);
                                } else {
                                    $modelData[$modelKey] = trim((string) $val);
                                }
                            }
                        }

                        if (empty($modelData['id_card']) || empty($modelData['period_belong'])) {
                            throw new \Exception('身份证号或费款所属期缺失');
                        }

                        // 自动提取年度和月份
                        $period = (string) $modelData['period_belong'];
                        if (preg_match('/^(\d{4})[^\d]?(\d{1,2})/', $period, $m)) {
                            $modelData['year'] = (int) $m[1];
                            $modelData['month'] = (int) $m[2];
                        } else {
                            $modelData['year'] = (int) date('Y');
                        }

                        // 预先尝试获取配额（缓存逻辑，支持归一化弹性匹配）
                        $cacheKey = "{$modelData['year']}_{$modelData['category']}";
                        if (!isset($quotaCache[$cacheKey])) {
                            $quotas = \App\Model\YfCategoryQuota::where('year', $modelData['year'])->get();
                            $targetCategory = $modelData['category'];
                            $matchedQuota = $quotas->first(function ($item) use ($targetCategory) {
                                return \App\Service\YfSettlementService::normalizeCategory($item->category) === $targetCategory;
                            });
                            $quotaCache[$cacheKey] = $matchedQuota ? (float) $matchedQuota->quota_amount : 0.00;
                        }
                        $modelData['_quota_amount'] = $quotaCache[$cacheKey];

                        // 执行核心计算逻辑
                        $calculatedData = $settlementService->calculateSettlement($modelData);

                        // 插入数据库
                        YfSettlement::create($calculatedData);
                        Db::commit();
                        $result['imported']++;

                    } catch (\Throwable $e) {
                        Db::rollBack();
                        // 限制记录错误数量，防止内存溢出
                        if (count($result['errors']) < 100) {
                            $result['errors'][] = "第" . ($rowIndex + 1) . "行: " . $e->getMessage();
                        }
                        $logger->warning("Row import failed at index {$rowIndex}: " . $e->getMessage());
                    }

                    $processed++;
                    if ($processed % 100 === 0 && $totalCount > 0) {
                        $this->updateProgress($this->uuid, min(($processed / $totalCount) * 100, 99.9));
                    }
                }
            );

            $this->finalizeImportTask($result);

        } catch (\Throwable $e) {
            $this->failTask($e);
        } finally {
            if (file_exists($this->tempFile)) {
                @unlink($this->tempFile);
            }
            $this->releaseLock();
        }
    }

    private function parseDate($dateStr): ?string
    {
        if ($dateStr === null || $dateStr === '') {
            return null;
        }
        $dateStr = trim((string) $dateStr);
        if ($dateStr === '' || $dateStr === '0' || $dateStr === '0000-00-00') {
            return null;
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $dateStr, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }
        $timestamp = strtotime($dateStr);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    protected function finalizeImportTask(array $result): void
    {
        $errorCount = count($result['errors']);
        $summary = sprintf("(成功%d/失败%d)", $result['imported'], $errorCount);

        // 如果全部失败，尝试抓取第一条错误原因并回显在标题中，方便排障
        if ($result['imported'] === 0 && $errorCount > 0) {
            $firstError = $result['errors'][0] ?? '未知错误';
            // 简单清洗下错误信息，只取核心原因部分（冒号后面）
            if (str_contains($firstError, ': ')) {
                $firstError = explode(': ', $firstError)[1] ?? $firstError;
            }
            if (mb_strlen($firstError) > 20) {
                $firstError = mb_substr($firstError, 0, 20) . "...";
            }
            $summary .= " 原因:{$firstError}";
        }

        // 获取当前任务标题并附加统计信息
        $task = \App\Model\Task::where('uuid', $this->uuid)->first();
        $title = $task ? $task->title : '优抚联网结算导入';
        if (!str_contains($title, '(')) {
            $title .= $summary;
        }

        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'status' => Task::STATUS_COMPLETED,
            'title' => $title,
            'url_at' => date('Y-m-d H:i:s')
        ]);

        $this->releaseLock();
    }
}

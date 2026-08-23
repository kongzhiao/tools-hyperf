<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\YfSettlement;
use App\Model\YfCategoryQuota;
use Hyperf\DbConnection\Db;

class YfSettlementService
{
    /**
     * 归一化分类名称：移除所有空格，并将连续的分隔符统一
     */
    public static function normalizeCategory(string $category): string
    {
        // 1. 将全角空格转半角
        $category = str_replace('　', ' ', $category);
        // 2. 移除所有空格
        $category = str_replace(' ', '', $category);
        // 3. 统一分隔符（支持 / 和 \）
        $category = str_replace(['\\', '|', '-'], '/', $category);
        // 4. 移除重复的 /
        $category = preg_replace('/\/+/', '/', $category);

        return trim($category, '/ ');
    }

    /**
     * 计算并匹配结算数据的补助金额
     * 
     * @param array $data 导入的原始数据
     * @param int|null $excludeId 需要排除的记录ID（用于重新计算）
     * @return array 包含计算结果的数据
     */
    public function calculateSettlement(array $data, ?int $excludeId = null): array
    {
        // 1. 基础金额计算
        // 医保报销和医疗救助金额 = (符合医保范围金额 - 基本医疗基金支出 - 大病补充 - 大额补充 - 医疗救助 - 倾斜救助 - 扶贫济困 - 渝快保)
        $eligibleAmount = (float) ($data['eligible_amount'] ?? 0);
        $fundPay = (float) ($data['fund_pay'] ?? 0);
        $seriousIllnessPay = (float) ($data['serious_illness_pay'] ?? 0);
        $largeAmountPay = (float) ($data['large_amount_pay'] ?? 0);
        $medicalAssistance = (float) ($data['medical_assistance'] ?? 0);
        $slantAssistance = (float) ($data['slant_assistance'] ?? 0);
        $povertyAssistance = (float) ($data['poverty_assistance'] ?? 0);
        $yukaibaoPay = (float) ($data['yukaibao_pay'] ?? 0);

        $insAssistTotal = $eligibleAmount - $fundPay - $seriousIllnessPay - $largeAmountPay
            - $medicalAssistance - $slantAssistance - $povertyAssistance - $yukaibaoPay;

        // 保证不为负数
        $insAssistTotal = max(0, round($insAssistTotal, 2));

        // 符合优抚住院医疗补助计算金额 = 医保报销和医疗救助金额 * 50%
        $yfEligibleAmount = round($insAssistTotal * 0.5, 2);

        // 2. 额度匹配与限额逻辑
        $idCard = (string) ($data['id_card'] ?? '');
        $periodBelong = (string) ($data['period_belong'] ?? ''); // 格式 YYYYMM
        $year = (int) substr($periodBelong, 0, 4);
        $category = self::normalizeCategory((string) ($data['category'] ?? ''));

        // 获取年度补助限额 (增加容错匹配)
        if (isset($data['_quota_amount'])) {
            $quota = (float) $data['_quota_amount'];
        } else {
            // 获取该年度所有配额记录，然后在内存中进行归一化匹配
            $quotas = YfCategoryQuota::where('year', $year)->get();
            $matchedQuota = $quotas->first(function ($item) use ($category) {
                return self::normalizeCategory($item->category) === $category;
            });
            $quota = $matchedQuota ? (float) $matchedQuota->quota_amount : 0.00;
        }

        // 获取该人该年度已使用的补助总额
        $usedAmountQuery = YfSettlement::whereBlind('id_card', $idCard)
            ->where('period_belong', 'like', "{$year}%");

        if ($excludeId) {
            $usedAmountQuery->where('id', '!=', $excludeId);
        }

        $usedAmount = (float) $usedAmountQuery->sum('current_subsidy');

        // 本次可用额度 = 年度总额 - 已使用总额
        $availableQuota = max(0, round($quota - $usedAmount, 2));

        // 本次补助金额 = min(符合优抚计算金额, 本次可用额度)
        $currentSubsidy = min($yfEligibleAmount, $availableQuota);
        $currentSubsidy = max(0, round($currentSubsidy, 2));

        // 剩余金额 = 年度补助限额 - 已使用金额 - 本次补助金额
        $remainingAmount = max(0, round($quota - $usedAmount - $currentSubsidy, 2));

        // 3. 支付状态判定
        // 当符合优抚计算金额=0 或者 剩余金额=0 (这里剩余金额指的是计算前就已经没额度了且本次补助为0，
        // 或者本次计算后刚好用完) -> 需求说：当符合计算金额=0 or 剩余金额=0 时支付状态为-1
        // 注意：如果本次补助>0，即便剩余额度变为0，也说明本次产生了支付。
        $payStatus = 0;
        if ($yfEligibleAmount <= 0 || ($availableQuota <= 0 && $currentSubsidy <= 0)) {
            $payStatus = -1;
        }

        return array_merge($data, [
            'ins_assist_total' => $insAssistTotal,
            'yf_eligible_amount' => $yfEligibleAmount,
            'annual_quota' => $quota,
            'used_amount' => $usedAmount,
            'current_subsidy' => $currentSubsidy,
            'remaining_amount' => $remainingAmount,
            'pay_status' => $payStatus,
        ]);
    }

    /**
     * 获取汇总统计
     */
    public function getStatistics(array $filters): array
    {
        $query = YfSettlement::query();
        $this->applyFilters($query, $filters);

        return [
            'total_count' => $query->count(),
            'total_medical_cost' => round((float) $query->sum('total_amount'), 2),
            'total_yf_subsidy' => round((float) $query->sum('current_subsidy'), 2),
            'pending_pay_count' => (clone $query)->where('pay_status', 0)->count(),
            'pending_pay_amount' => round((float) (clone $query)->where('pay_status', 0)->sum('current_subsidy'), 2),
            'paid_count' => (clone $query)->where('pay_status', 1)->count(),
            'paid_amount' => round((float) (clone $query)->where('pay_status', 1)->sum('current_subsidy'), 2),
            'no_pay_needed_count' => (clone $query)->where('pay_status', -1)->count(),
        ];
    }

    /**
     * 应用通用过滤器
     */
    public function applyFilters($query, array $filters): void
    {
        // 1. 基础字段筛选
        if (!empty($filters['year'])) {
            $query->where('year', (int) $filters['year']);
        }
        if (!empty($filters['month'])) {
            $query->where('month', (int) $filters['month']);
        }
        if (!empty($filters['name'])) {
            $query->whereBlind('name', (string) $filters['name']);
        }
        if (!empty($filters['id_card'])) {
            $query->whereBlind('id_card', (string) $filters['id_card']);
        }
        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        // 兼容处理：insurance_type 与 medical_category 均指向同一个库字段
        if (!empty($filters['insurance_type'])) {
            $query->where('medical_category', $filters['insurance_type']);
        }
        if (!empty($filters['medical_category'])) {
            $query->where('medical_category', $filters['medical_category']);
        }
        if (isset($filters['pay_status']) && $filters['pay_status'] !== '') {
            $query->where('pay_status', (int) $filters['pay_status']);
        }

        // 2. 日期范围筛选
        // 入院日期
        if (!empty($filters['admission_date_start'])) {
            $query->where('admission_date', '>=', $filters['admission_date_start']);
        }
        if (!empty($filters['admission_date_end'])) {
            $query->where('admission_date', '<=', $filters['admission_date_end']);
        }
        // 出院日期
        if (!empty($filters['discharge_date_start'])) {
            $query->where('discharge_date', '>=', $filters['discharge_date_start']);
        }
        if (!empty($filters['discharge_date_end'])) {
            $query->where('discharge_date', '<=', $filters['discharge_date_end']);
        }
        // 结算日期
        if (!empty($filters['settlement_date_start'])) {
            $query->where('settlement_date', '>=', $filters['settlement_date_start']);
        }
        if (!empty($filters['settlement_date_end'])) {
            $query->where('settlement_date', '<=', $filters['settlement_date_end']);
        }
        // 支付时间
        if (!empty($filters['pay_at_start'])) {
            $query->where('pay_at', '>=', $filters['pay_at_start'] . ' 00:00:00');
        }
        if (!empty($filters['pay_at_end'])) {
            $query->where('pay_at', '<=', $filters['pay_at_end'] . ' 23:59:59');
        }

        // 3. 金额范围筛选
        // 符合优抚住院医疗补助计算金额 (yf_eligible_amount)
        if (isset($filters['yf_eligible_amount_min']) && $filters['yf_eligible_amount_min'] !== '') {
            $query->where('yf_eligible_amount', '>=', (float) $filters['yf_eligible_amount_min']);
        }
        if (isset($filters['yf_eligible_amount_max']) && $filters['yf_eligible_amount_max'] !== '') {
            $query->where('yf_eligible_amount', '<=', (float) $filters['yf_eligible_amount_max']);
        }
        // 剩余金额 (remaining_amount)
        if (isset($filters['remaining_amount_min']) && $filters['remaining_amount_min'] !== '') {
            $query->where('remaining_amount', '>=', (float) $filters['remaining_amount_min']);
        }
        if (isset($filters['remaining_amount_max']) && $filters['remaining_amount_max'] !== '') {
            $query->where('remaining_amount', '<=', (float) $filters['remaining_amount_max']);
        }
    }

    /**
     * 重新计算现有数据
     */
    public function recalculateSettlements(array $filters): void
    {
        $query = YfSettlement::query();
        $this->applyFilters($query, $filters);

        // 使用 chunk 避免内存溢出
        $query->chunk(100, function ($records) {
            foreach ($records as $record) {
                // 将模型转换为数组作为计算输入
                $data = $record->toArray();

                // 执行计算逻辑 (重算时排除自身 ID)
                $calculatedData = $this->calculateSettlement($data, (int) ($data['id'] ?? 0));

                // 更新记录字段
                $record->fill($calculatedData);
                if ($record->isDirty()) {
                    $record->save();
                }
            }
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class InsuranceData extends Model
{
    protected ?string $table = 'insurance_data';

    protected array $fillable = [
        'year',
        'name',
        'id_type',
        'id_number',
        'person_number',
        'street_town',
        'medical_category',
        'total_cost',
        'eligible_reimbursement',
        'basic_medical_reimbursement',
        'serious_illness_reimbursement',
        'large_amount_reimbursement',
        'medical_assistance',
        'tilt_assistance',
        'medical_assistance_category',
        'level',
        'level_match_status',
        'assistance_identity_match_status',
        'street_town_match_status',
        'match_status',
        'import_batch',
        'medical_assistance_category_match',
        'payment_amount',
        'payment_category',
        'personal_amount',
        'payment_date',
        'remark',
        'serial_number',
        'category_match',
        'assistance_identity',
        'street_town_name',
    ];

    protected array $casts = [
        'total_cost' => 'decimal:2',
        'eligible_reimbursement' => 'decimal:2',
        'basic_medical_reimbursement' => 'decimal:2',
        'serious_illness_reimbursement' => 'decimal:2',
        'large_amount_reimbursement' => 'decimal:2',
        'medical_assistance' => 'decimal:2',
        'tilt_assistance' => 'decimal:2',
        'payment_amount' => 'decimal:2',
        'personal_amount' => 'decimal:2',
    ];

    /**
     * 获取所有年份
     */
    public static function getAllYears(): array
    {
        // 从年份管理表获取所有活跃年份
        $managedYears = \App\Model\InsuranceYear::getAllActiveYears();
        
        // 从数据表获取实际有数据的年份
        $dataYears = self::distinct()->pluck('year')->sort()->values()->toArray();
        
        // 合并两个数组并去重，优先保留管理表的年份
        $allYears = array_unique(array_merge($managedYears, $dataYears));
        sort($allYears);
        
        return $allYears;
    }

    /**
     * 获取所有街道乡镇
     */
    public static function getAllStreetTowns(): array
    {
        return self::distinct()->pluck('street_town')->sort()->values()->toArray();
    }

    /**
     * 获取所有代缴类别
     */
    public static function getAllPaymentCategories(): array
    {
        return self::distinct()->pluck('payment_category')->sort()->values()->toArray();
    }

    /**
     * 获取所有档次
     */
    public static function getAllLevels(): array
    {
        return self::distinct()->pluck('level')->whereNotNull()->sort()->values()->toArray();
    }

    /**
     * 获取所有医疗救助类别
     */
    public static function getAllMedicalAssistanceCategories(): array
    {
        return self::distinct()->pluck('medical_assistance_category')->whereNotNull()->sort()->values()->toArray();
    }

    /**
     * 根据条件搜索数据
     */
    public static function search(array $filters, int $page = 1, int $pageSize = 15): array
    {
        $query = self::query();

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
            $query->where('payment_category', 'like', "%{$filters['payment_category']}%");
        }

        if (isset($filters['level']) && $filters['level'] !== '') {
            $query->where('level', 'like', "%{$filters['level']}%");
        }

        if (!empty($filters['medical_assistance_category'])) {
            $query->where('medical_assistance_category', 'like', "%{$filters['medical_assistance_category']}%");
        }

        // 这些字段的值如果为空则忽略，否则值必须是 'matched' 或 'unmatched'
        if (isset($filters['level_match_status']) && $filters['level_match_status'] !== '') {
            if (in_array($filters['level_match_status'], ['matched', 'unmatched'], true)) {
                $query->where('level_match_status', $filters['level_match_status']);
            }
        }

        if (isset($filters['assistance_identity_match_status']) && $filters['assistance_identity_match_status'] !== '') {
            if (in_array($filters['assistance_identity_match_status'], ['matched', 'unmatched'], true)) {
                $query->where('assistance_identity_match_status', $filters['assistance_identity_match_status']);
            }
        }

        if (isset($filters['street_town_match_status']) && $filters['street_town_match_status'] !== '') {
            if (in_array($filters['street_town_match_status'], ['matched', 'unmatched'], true)) {
                $query->where('street_town_match_status', $filters['street_town_match_status']);
            }
        }

        if (isset($filters['match_status']) && $filters['match_status'] !== '') {
            if (in_array($filters['match_status'], ['matched', 'unmatched'], true)) {
                $query->where('match_status', $filters['match_status']);
            } elseif ($filters['match_status'] === 'unmatched_data') {
                // 筛选既不是matched也不是unmatched的数据（空值或null）
                $query->where(function($q) {
                    $q->whereNull('match_status')
                      ->orWhere('match_status', '')
                      ->orWhereNotIn('match_status', ['matched', 'unmatched']);
                });
            }
        }


        $total = $query->count();
        $data = $query->orderBy('id', 'desc')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return [
            'list' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取统计数据
     * @param int|null $year
     * @return array
     */
    public static function getStatistics(int $year = null): array
    {
        $baseQuery = static::query();
        if ($year) {
            $baseQuery->where('year', $year);
        }

        // 获取总记录数
        $totalCount = (clone $baseQuery)->count();

        // 获取正确数据数
        try {
            $matchedCount = (clone $baseQuery)
                ->where('match_status', 'matched')
                ->count();

            // 获取疑点数据数（明确标记为unmatched的数据）
            $unmatchedCount = (clone $baseQuery)
                ->where('match_status', 'unmatched')
                ->count();

            // 获取未匹配数据数（空值或null的数据）
            $unmatchedDataCount = (clone $baseQuery)
                ->where(function ($query) {
                    $query->whereNull('match_status')
                          ->orWhere('match_status', '')
                          ->orWhereNotIn('match_status', ['matched', 'unmatched']);
                })
                ->count();

            // 获取疑点数据数（有异常情况的数据，但可能不是通过match_status字段判断的）
            $suspiciousCount = (clone $baseQuery)
                ->where(function ($query) {
                    $query->where('payment_amount', '<=', 0)
                        ->orWhereNull('payment_amount')
                        ->orWhere('personal_amount', '<', 0)
                        ->orWhereNull('personal_amount')
                        ->orWhere('id_number', '')
                        ->orWhereNull('id_number');
                })
                ->count();
        } catch (\Exception $e) {
            // 如果字段不存在，返回默认值
            $matchedCount = 0;
            $unmatchedCount = 0;
            $unmatchedDataCount = $totalCount;
            $suspiciousCount = 0;
        }

        // 获取总金额
        $totalPayment = (clone $baseQuery)->sum('payment_amount') ?? 0;

        return [
            'total' => $totalCount,
            'total_count' => $totalCount,
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'unmatched_data_count' => $unmatchedDataCount,
            'suspicious_count' => $suspiciousCount,
            'total_payment' => $totalPayment,
            'payment_formatted' => number_format(floatval($totalPayment), 2) . ' 元',
        ];
    }
} 
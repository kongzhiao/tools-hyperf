<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class InsuranceLevelConfig extends Model
{
    protected ?string $table = 'insurance_level_configs';

    protected array $fillable = [
        'year',
        'payment_category',
        'level',
        'subsidy_amount',
        'personal_amount',
        'effective_period',
        'payment_department',
        'remark',
    ];

    protected array $casts = [
        'year' => 'integer',
        'subsidy_amount' => 'decimal:2',
        'personal_amount' => 'decimal:2',
    ];

    /**
     * 获取所有年份
     */
    public static function getAllYears(): array
    {
        return self::distinct()->pluck('year')->sort()->values()->toArray();
    }

    /**
     * 获取指定年份的配置
     */
    public static function getByYear(int $year): \Hyperf\Database\Model\Collection
    {
        return self::where('year', $year)->orderBy('payment_category')->orderBy('level')->get();
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
        return self::distinct()->pluck('level')->sort()->values()->toArray();
    }

    /**
     * 获取最近年份的配置作为模板
     */
    public static function getLatestYearTemplate(): \Hyperf\Database\Model\Collection
    {
        $latestYear = self::max('year');
        if (!$latestYear) {
            return new \Hyperf\Database\Model\Collection();
        }
        
        return self::where('year', $latestYear)
            ->orderBy('payment_category')
            ->orderBy('level')
            ->get();
    }

    /**
     * 批量创建配置
     */
    public static function batchCreate(array $configs): bool
    {
        try {
            foreach ($configs as $config) {
                self::create($config);
            }
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查年份是否存在配置
     */
    public static function yearExists(int $year): bool
    {
        return self::where('year', $year)->exists();
    }

    /**
     * 删除指定年份的所有配置
     */
    public static function deleteByYear(int $year): int
    {
        return self::where('year', $year)->delete();
    }
} 
<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class InsuranceYear extends Model
{
    protected ?string $table = 'insurance_years';

    protected array $fillable = [
        'year',
        'description',
        'is_active',
    ];

    protected array $casts = [
        'year' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * 获取所有活跃的年份
     */
    public static function getAllActiveYears(): array
    {
        return self::where('is_active', true)
            ->orderBy('year', 'asc')
            ->pluck('year')
            ->toArray();
    }

    /**
     * 创建新年份
     */
    public static function createYear(int $year, string $description = null): bool
    {
        try {
            self::create([
                'year' => $year,
                'description' => $description,
                'is_active' => true,
            ]);
            return true;
        } catch (\Exception $e) {
            // 记录错误信息
            error_log("创建年份失败: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 检查年份是否存在
     */
    public static function yearExists(int $year): bool
    {
        return self::where('year', $year)->exists();
    }
} 
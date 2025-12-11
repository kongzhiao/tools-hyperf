<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\InsuranceLevelConfig;
use Hyperf\Database\Model\Collection;
use Illuminate\Support\Collection as SupportCollection;

class InsuranceLevelConfigCache
{
    /**
     * 缓存存储
     * 结构: [year => [payment_category => Collection]]
     */
    private static array $cache = [];

    /**
     * 加载指定年份的所有档次配置到缓存
     */
    public static function loadConfigsForYear(int $year): void
    {
        if (!isset(self::$cache[$year])) {
            $configs = InsuranceLevelConfig::where('year', $year)
                ->orderBy('payment_category')
                ->orderBy('level')
                ->get();

            // 按代缴类别分组
            self::$cache[$year] = $configs->groupBy('payment_category');
        }
    }

    /**
     * 查找匹配的档次配置
     * 
     * @param int $year 年份
     * @param string $paymentCategory 代缴类别
     * @param float $paymentAmount 代缴金额
     * @return Collection 匹配的配置集合
     */
    public static function findMatchingConfigs(int $year, string $paymentCategory, float $paymentAmount): Collection
    {
        self::loadConfigsForYear($year);

        if (!isset(self::$cache[$year][$paymentCategory])) {
            return new Collection();
        }

        $filtered = self::$cache[$year][$paymentCategory]->filter(function ($config) use ($paymentAmount) {
            return abs($config->subsidy_amount - $paymentAmount) < 0.01;
        });

        // 转换为 Collection 对象
        return new Collection($filtered->values()->all());
    }

    /**
     * 获取指定年份和代缴类别的所有可用配置
     * 
     * @param int $year 年份
     * @param string $paymentCategory 代缴类别
     * @return Collection 配置集合
     */
    public static function getAvailableConfigs(int $year, string $paymentCategory): Collection
    {
        self::loadConfigsForYear($year);

        if (!isset(self::$cache[$year][$paymentCategory])) {
            return new Collection();
        }

        return self::$cache[$year][$paymentCategory];
    }

    /**
     * 获取指定年份的所有配置（按代缴类别分组）
     * 
     * @param int $year 年份
     * @return Collection 配置集合
     */
    public static function getAllConfigsForYear(int $year): Collection
    {
        self::loadConfigsForYear($year);

        $allConfigs = new Collection();
        foreach (self::$cache[$year] as $categoryConfigs) {
            $allConfigs = $allConfigs->merge($categoryConfigs);
        }

        return $allConfigs;
    }

    /**
     * 清除指定年份的缓存
     * 
     * @param int $year 年份
     */
    public static function clearCacheForYear(int $year): void
    {
        unset(self::$cache[$year]);
    }

    /**
     * 清除所有缓存
     */
    public static function clearAllCache(): void
    {
        self::$cache = [];
    }

    /**
     * 检查缓存是否已加载
     * 
     * @param int $year 年份
     * @return bool
     */
    public static function isCached(int $year): bool
    {
        return isset(self::$cache[$year]);
    }

    /**
     * 获取缓存统计信息
     * 
     * @return array
     */
    public static function getCacheStats(): array
    {
        $stats = [];
        foreach (self::$cache as $year => $categories) {
            $totalConfigs = 0;
            foreach ($categories as $configs) {
                $totalConfigs += $configs->count();
            }
            $stats[$year] = [
                'categories' => count($categories),
                'total_configs' => $totalConfigs
            ];
        }
        return $stats;
    }
}

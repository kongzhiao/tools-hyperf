<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\CategoryConversion;
use Illuminate\Support\Collection;

class CategoryConversionCache
{
    /**
     * @var array [value => CategoryConversion]
     */
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * 预加载所有转换规则
     */
    public static function loadAll(): void
    {
        if (self::$loaded) {
            return;
        }

        $all = CategoryConversion::all();
        foreach ($all as $item) {
            // 索引三个映射字段，实现 O(1) 检索，并处理潜在空格
            $taxStandard = trim((string) $item->tax_standard);
            $nationalName = trim((string) $item->national_dict_name);
            $exportStandard = trim((string) $item->medical_export_standard);

            if (!empty($taxStandard)) {
                self::$cache[$taxStandard] = $item;
            }
            if (!empty($nationalName)) {
                self::$cache[$nationalName] = $item;
            }
            if (!empty($exportStandard)) {
                self::$cache[$exportStandard] = $item;
            }
        }

        self::$loaded = true;
    }

    /**
     * 内存中查找匹配项
     */
    public static function findByAnyValue(string $value): ?CategoryConversion
    {
        self::loadAll();
        return self::$cache[trim($value)] ?? null;
    }

    public static function clear(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }
}

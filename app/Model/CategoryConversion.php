<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class CategoryConversion extends Model
{
    protected ?string $table = 'category_conversions';

    protected array $fillable = [
        'tax_standard',
        'medical_export_standard',
        'national_dict_name',
    ];

    protected array $casts = [
        'id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 根据医保数据导出对象口径查找对应的税务代缴数据口径
     */
    public static function findByMedicalExportStandard(string $medicalExportStandard): ?self
    {
        return self::where('medical_export_standard', $medicalExportStandard)->first();
    }

    /**
     * 根据国家字典值名称查找对应的税务代缴数据口径
     */
    public static function findByNationalDictName(string $nationalDictName): ?self
    {
        return self::where('national_dict_name', $nationalDictName)->first();
    }

    /**
     * 根据任意值查找对应的税务代缴数据口径
     */
    public static function findByAnyValue(string $value): ?self
    {
        return self::where('medical_export_standard', $value)
            ->orWhere('national_dict_name', $value)
            ->first();
    }

    /**
     * 获取所有税务代缴数据口径
     */
    public static function getAllTaxStandards(): array
    {
        return self::distinct()->pluck('tax_standard')->toArray();
    }

    /**
     * 根据税务代缴数据口径获取所有相关记录
     */
    public static function getByTaxStandard(string $taxStandard): array
    {
        return self::where('tax_standard', $taxStandard)->get()->toArray();
    }
} 
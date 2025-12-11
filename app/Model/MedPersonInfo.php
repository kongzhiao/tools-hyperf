<?php

declare(strict_types=1);

namespace App\Model;

class MedPersonInfo extends Model
{
    protected ?string $table = 'med_person_info';

    protected array $fillable = [
        'name',
        'id_card',
        'insurance_area',
    ];

    protected array $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联就诊记录
     */
    public function medicalRecords()
    {
        return $this->hasMany(MedMedicalRecord::class, 'person_id');
    }

    /**
     * 关联报销明细
     */
    public function reimbursementDetails()
    {
        return $this->hasMany(MedReimbursementDetail::class, 'person_id');
    }

    /**
     * 根据条件搜索个人信息
     */
    public static function search(array $filters, int $page = 1, int $pageSize = 15): array
    {
        $query = self::query();

        if (!empty($filters['name'])) {
            $query->where('name', 'like', "%{$filters['name']}%");
        }

        if (!empty($filters['id_card'])) {
            $query->where('id_card', 'like', "%{$filters['id_card']}%");
        }

        if (!empty($filters['insurance_area'])) {
            $query->where('insurance_area', 'like', "%{$filters['insurance_area']}%");
        }

        $total = $query->count();
        $data = $query->offset(($page - 1) * $pageSize)
                     ->limit($pageSize)
                     ->orderBy('created_at', 'desc')
                     ->get();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取所有参保地区
     */
    public static function getAllInsuranceAreas(): array
    {
        return self::distinct()->pluck('insurance_area')->sort()->values()->toArray();
    }

    /**
     * 根据身份证号查找个人信息
     */
    public static function findByIdCard(string $idCard): ?self
    {
        return self::where('id_card', $idCard)->first();
    }
} 
<?php

declare(strict_types=1);

namespace App\Model;

class MedMedicalRecord extends Model
{
    protected ?string $table = 'med_medical_record';

    protected array $fillable = [
        'person_id',
        'hospital_name',
        'visit_type',
        'admission_date',
        'discharge_date',
        'settlement_date',
        'total_cost',
        'policy_covered_cost',
        'pool_reimbursement_amount',
        'large_amount_reimbursement_amount',
        'critical_illness_reimbursement_amount',
        'medical_assistance_amount',
        'excess_reimbursement_amount',
        'processing_status',
    ];

    protected array $casts = [
        'admission_date' => 'date',
        'discharge_date' => 'date',
        'settlement_date' => 'date',
        'total_cost' => 'decimal:2',
        'policy_covered_cost' => 'decimal:2',
        'pool_reimbursement_amount' => 'decimal:2',
        'large_amount_reimbursement_amount' => 'decimal:2',
        'critical_illness_reimbursement_amount' => 'decimal:2',
        'medical_assistance_amount' => 'decimal:2',
        'excess_reimbursement_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联个人信息
     */
    public function personInfo()
    {
        return $this->belongsTo(MedPersonInfo::class, 'person_id');
    }

    /**
     * 关联报销明细
     */
    public function reimbursementDetail()
    {
        return $this->hasOne(MedReimbursementDetail::class, 'medical_record_id');
    }

    /**
     * 根据条件搜索就诊记录
     */
    public static function search(array $filters, int $page = 1, int $pageSize = 15): array
    {
        $query = self::query()->with('personInfo');

        if (!empty($filters['person_id'])) {
            $query->where('person_id', $filters['person_id']);
        }

        if (!empty($filters['hospital_name'])) {
            $query->where('hospital_name', 'like', "%{$filters['hospital_name']}%");
        }

        if (!empty($filters['visit_type'])) {
            $query->where('visit_type', $filters['visit_type']);
        }

        if (!empty($filters['processing_status'])) {
            $query->where('processing_status', $filters['processing_status']);
        }

        if (!empty($filters['admission_date_start'])) {
            $query->where('admission_date', '>=', $filters['admission_date_start']);
        }

        if (!empty($filters['admission_date_end'])) {
            $query->where('admission_date', '<=', $filters['admission_date_end']);
        }

        $total = $query->count();
        $data = $query->offset(($page - 1) * $pageSize)
                     ->limit($pageSize)
                     ->orderBy('admission_date', 'desc')
                     ->get();

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取所有就诊类别
     */
    public static function getAllVisitTypes(): array
    {
        return self::distinct()->pluck('visit_type')->sort()->values()->toArray();
    }

    /**
     * 获取所有医院名称
     */
    public static function getAllHospitalNames(): array
    {
        return self::distinct()->pluck('hospital_name')->sort()->values()->toArray();
    }

    /**
     * 获取所有处理状态
     */
    public static function getAllProcessingStatuses(): array
    {
        return ['unreimbursed', 'reimbursed', 'returned'];
    }
} 
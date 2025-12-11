<?php

declare(strict_types=1);

namespace App\Model;

class MedReimbursementDetail extends Model
{
    protected ?string $table = 'med_reimbursement_detail';

    protected array $fillable = [
        'person_id',
        'medical_record_ids',
        'bank_name',
        'bank_account',
        'account_name',
        'total_amount',
        'policy_covered_amount',
        'pool_reimbursement_amount',
        'large_amount_reimbursement_amount',
        'critical_illness_reimbursement_amount',
        'pool_reimbursement_ratio',
        'large_amount_reimbursement_ratio',
        'critical_illness_reimbursement_ratio',
        'reimbursement_status',
    ];

    protected array $casts = [
        'medical_record_ids' => 'array',
        'total_amount' => 'decimal:2',
        'policy_covered_amount' => 'decimal:2',
        'pool_reimbursement_amount' => 'decimal:2',
        'large_amount_reimbursement_amount' => 'decimal:2',
        'critical_illness_reimbursement_amount' => 'decimal:2',
        'pool_reimbursement_ratio' => 'decimal:2',
        'large_amount_reimbursement_ratio' => 'decimal:2',
        'critical_illness_reimbursement_ratio' => 'decimal:2',
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
     * 获取关联的就诊记录
     */
    public function getMedicalRecords()
    {
        if (empty($this->medical_record_ids)) {
            return collect();
        }
        
        // 确保 medical_record_ids 是数组格式
        $recordIds = is_array($this->medical_record_ids) ? $this->medical_record_ids : [$this->medical_record_ids];
        
        return MedMedicalRecord::whereIn('id', $recordIds)->get();
    }

    /**
     * 根据条件搜索报销明细
     */
    public static function search(array $filters, int $page = 1, int $pageSize = 15): array
    {
        $query = self::query()->with(['personInfo']);

        if (!empty($filters['person_id'])) {
            $query->where('person_id', $filters['person_id']);
        }

        if (!empty($filters['medical_record_id'])) {
            $query->whereJsonContains('medical_record_ids', $filters['medical_record_id']);
        }

        if (!empty($filters['medical_record_ids'])) {
            $query->whereJsonContains('medical_record_ids', $filters['medical_record_ids']);
        }

        if (!empty($filters['bank_name'])) {
            $query->where('bank_name', 'like', "%{$filters['bank_name']}%");
        }

        if (!empty($filters['reimbursement_status'])) {
            $query->where('reimbursement_status', $filters['reimbursement_status']);
        }

        if (!empty($filters['account_name'])) {
            $query->where('account_name', 'like', "%{$filters['account_name']}%");
        }

        $total = $query->count();
        $data = $query->offset(($page - 1) * $pageSize)
                     ->limit($pageSize)
                     ->orderBy('created_at', 'desc')
                     ->get();

        // 为每个受理记录加载关联的就诊记录
        $data->each(function ($item) {
            $item->medical_records = $item->getMedicalRecords();
        });

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * 获取所有银行名称
     */
    public static function getAllBankNames(): array
    {
        return self::distinct()->pluck('bank_name')->sort()->values()->toArray();
    }

    /**
     * 获取所有报销状态
     */
    public static function getAllReimbursementStatuses(): array
    {
        return ['pending', 'processed', 'void'];
    }

    /**
     * 获取报销统计信息
     */
    public static function getStatistics(): array
    {
        $totalCount = self::count();
        $pendingCount = self::where('reimbursement_status', 'pending')->count();
        $processedCount = self::where('reimbursement_status', 'processed')->count();
        $voidCount = self::where('reimbursement_status', 'void')->count();

        $totalAmount = self::where('reimbursement_status', 'processed')->sum('total_amount');
        $poolAmount = self::where('reimbursement_status', 'processed')->sum('pool_reimbursement_amount');
        $largeAmount = self::where('reimbursement_status', 'processed')->sum('large_amount_reimbursement_amount');
        $criticalIllnessAmount = self::where('reimbursement_status', 'processed')->sum('critical_illness_reimbursement_amount');

        return [
            'total_count' => $totalCount,
            'pending_count' => $pendingCount,
            'processed_count' => $processedCount,
            'void_count' => $voidCount,
            'total_amount' => $totalAmount,
            'pool_amount' => $poolAmount,
            'large_amount' => $largeAmount,
            'critical_illness_amount' => $criticalIllnessAmount,
        ];
    }
} 
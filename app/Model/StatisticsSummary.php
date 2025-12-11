<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class StatisticsSummary extends Model
{
    protected ?string $table = 'statistics_summary';

    protected array $fillable = [
        'project_code',
        'data_type',
        'street_town',
        'name',
        'id_type',
        'id_number',
        'medical_category',
        'total_cost',
        'eligible_reimbursement',
        'basic_medical_reimbursement',
        'serious_illness_reimbursement',
        'large_amount_reimbursement',
        'medical_assistance',
        'tilt_assistance',
        'person_number',
        'payment_category',
        'payment_amount',
        'payment_date',
        'level',
        'personal_amount',
        'medical_assistance_category',
        'remark',
        'import_batch',
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
        'payment_date' => 'date',
        'personal_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * 关联项目
     */
    public function project()
    {
        return $this->belongsTo(Project::class, 'project_code', 'code');
    }
} 
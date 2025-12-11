<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class StatisticsData extends Model
{
    protected ?string $table = 'statistics_data';

    protected array $fillable = [
        'project_id',
        'import_type',
        'import_batch',
        'medical_category',
        'settlement_period',
        'fee_period',
        'settlement_id',
        'certification_place',
        'street_town',
        'insurance_place',
        'insurance_category',
        'id_number',
        'name',
        'assistance_identity',
        'visit_place',
        'medical_institution',
        'medical_visit_category',
        'medical_assistance_category',
        'disease_code',
        'disease_name',
        'admission_date',
        'discharge_date',
        'settlement_date',
        'total_cost',
        'eligible_reimbursement',
        'basic_medical_reimbursement',
        'serious_illness_reimbursement',
        'large_amount_reimbursement',
        'medical_assistance_amount',
        'medical_assistance',
        'tilt_assistance',
        'poverty_relief_amount',
        'yukuaibao_amount',
        'personal_account_amount',
        'personal_cash_amount',
    ];

    protected array $casts = [
        'total_cost' => 'decimal:2',
        'eligible_reimbursement' => 'decimal:2',
        'basic_medical_reimbursement' => 'decimal:2',
        'serious_illness_reimbursement' => 'decimal:2',
        'large_amount_reimbursement' => 'decimal:2',
        'medical_assistance_amount' => 'decimal:2',
        'medical_assistance' => 'decimal:2',
        'tilt_assistance' => 'decimal:2',
        'poverty_relief_amount' => 'decimal:2',
        'yukuaibao_amount' => 'decimal:2',
        'personal_account_amount' => 'decimal:2',
        'personal_cash_amount' => 'decimal:2',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
} 
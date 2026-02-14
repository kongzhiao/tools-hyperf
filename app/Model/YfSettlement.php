<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class YfSettlement extends Model
{
    protected ?string $table = 'yf_settlements';

    protected array $fillable = [
        'name',
        'id_card',
        'category',
        'medical_category',
        'year',
        'month',
        'period_clearing',
        'period_belong',
        'visit_address',
        'hospital_name',
        'disease_name',
        'admission_date',
        'discharge_date',
        'settlement_date',
        'total_amount',
        'eligible_amount',
        'fund_pay',
        'serious_illness_pay',
        'large_amount_pay',
        'enter_medical_assistance',
        'medical_assistance',
        'slant_assistance',
        'poverty_assistance',
        'yukaibao_pay',
        'personal_account_pay',
        'personal_cash_pay',
        'ins_assist_total',
        'yf_eligible_amount',
        'annual_quota',
        'used_amount',
        'current_subsidy',
        'remaining_amount',
        'pay_status',
        'pay_at',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'month' => 'integer',
        'total_amount' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'fund_pay' => 'decimal:2',
        'serious_illness_pay' => 'decimal:2',
        'large_amount_pay' => 'decimal:2',
        'enter_medical_assistance' => 'decimal:2',
        'medical_assistance' => 'decimal:2',
        'slant_assistance' => 'decimal:2',
        'poverty_assistance' => 'decimal:2',
        'yukaibao_pay' => 'decimal:2',
        'personal_account_pay' => 'decimal:2',
        'personal_cash_pay' => 'decimal:2',
        'ins_assist_total' => 'decimal:2',
        'yf_eligible_amount' => 'decimal:2',
        'annual_quota' => 'decimal:2',
        'used_amount' => 'decimal:2',
        'current_subsidy' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'pay_status' => 'integer',
        'pay_at' => 'datetime',
    ];
}

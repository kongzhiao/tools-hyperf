<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use App\Model\Town;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedRecord extends Model
{
    use SoftDeletes;

    protected ?string $table = 'unrescued_records';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'town_id' => 'integer',
        'total_fee' => 'decimal:2',
        'policy_fee' => 'decimal:2',
        'pool_fund_pay' => 'decimal:2',
        'large_amount_pay' => 'decimal:2',
        'serious_illness_pay' => 'decimal:2',
        'used_outpatient_rescue' => 'decimal:2',
        'used_normal_rescue' => 'decimal:2',
        'used_major_rescue' => 'decimal:2',
        'used_large_fee_rescue' => 'decimal:2',
        'calc_reimbursement_amount' => 'decimal:2',
        'distributed_at' => 'datetime',
        'received_at' => 'datetime',
        'notified_at' => 'datetime',
        'reimbursed_at' => 'datetime',
    ];

    public function town()
    {
        return $this->belongsTo(Town::class, 'town_id');
    }
}

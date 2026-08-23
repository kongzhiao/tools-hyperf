<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use App\Model\Town;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedRefundRecord extends Model
{
    use SoftDeletes;

    protected ?string $table = 'unrescued_refund_records';

    protected array $encrypts = ['name', 'id_card'];

    protected array $blindIndexes = ['name' => 'name_bidx', 'id_card' => 'id_card_bidx'];

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'town_id' => 'integer',
        'total_fee' => 'decimal:2',
        'policy_fee' => 'decimal:2',
        'pool_fund_pay' => 'decimal:2',
        'large_amount_pay' => 'decimal:2',
        'serious_illness_pay' => 'decimal:2',
        'medical_assistance_pay' => 'decimal:2',
        'yukuaibao_pay' => 'decimal:2',
        'personal_account_pay' => 'decimal:2',
        'personal_cash_pay' => 'decimal:2',
        'calc_reimbursement_amount' => 'decimal:2',
    ];

    public function town()
    {
        return $this->belongsTo(Town::class, 'town_id');
    }
}

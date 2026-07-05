<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use App\Model\Town;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedNoticeRecord extends Model
{
    use SoftDeletes;

    public const STATUS_PENDING = '待下放';
    public const STATUS_DISTRIBUTED = '已下放';
    public const STATUS_RECEIVED = '已接收';
    public const STATUS_NOTIFIED = '已通知';

    protected ?string $table = 'unrescued_notice_records';

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

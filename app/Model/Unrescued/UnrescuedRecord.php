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
        'status' => 'integer',
        'total_amount' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'fund_pay_amount' => 'decimal:2',
        'assistance_amount' => 'decimal:2',
        'self_pay_amount' => 'decimal:2',
        'estimated_assistance_amount' => 'decimal:2',
        'suggest_refund_amount' => 'decimal:2',
        'suggest_reissue_amount' => 'decimal:2',
        'raw_data' => 'array',
    ];

    public function town()
    {
        return $this->belongsTo(Town::class, 'town_id');
    }
}

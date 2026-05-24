<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use App\Model\Town;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedSupplementRecord extends Model
{
    use SoftDeletes;

    protected ?string $table = 'unrescued_supplement_records';

    protected array $fillable = [
        'record_id',
        'settlement_period',
        'name',
        'id_card',
        'town_id',
        'town_name',
        'should_amount',
        'actual_amount',
        'type',
        'status',
        'handled_at',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'record_id' => 'integer',
        'town_id' => 'integer',
        'should_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'type' => 'integer',
        'status' => 'integer',
        'handled_at' => 'datetime',
    ];

    public function town()
    {
        return $this->belongsTo(Town::class, 'town_id');
    }
}

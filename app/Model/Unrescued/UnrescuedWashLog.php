<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;

class UnrescuedWashLog extends Model
{
    protected ?string $table = 'unrescued_wash_logs';

    protected array $fillable = [
        'record_id',
        'rule_id',
        'rule_name',
        'wash_reason',
        'operator_id',
        'operator_name',
    ];

    protected array $casts = [
        'id' => 'integer',
        'record_id' => 'integer',
        'rule_id' => 'integer',
        'operator_id' => 'integer',
    ];
}

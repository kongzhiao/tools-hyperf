<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedWashLog extends Model
{
    use SoftDeletes;

    protected ?string $table = 'unrescued_wash_logs';

    protected array $fillable = [
        'settlement_period',
        'config_id',
        'batch_no',
        'total_count',
        'excluded_count',
        'kept_count',
        'summary',
        'created_by',
    ];

    protected array $casts = [
        'id' => 'integer',
        'config_id' => 'integer',
        'total_count' => 'integer',
        'excluded_count' => 'integer',
        'kept_count' => 'integer',
        'summary' => 'array',
        'created_by' => 'integer',
    ];
}

<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedWashConfig extends Model
{
    use SoftDeletes;

    protected ?string $table = 'unrescued_wash_configs';

    protected array $fillable = [
        'version',
        'name',
        'rule_name',
        'data',
        'is_active',
        'created_by',
    ];

    protected array $casts = [
        'id' => 'integer',
        'data' => 'array',
        'is_active' => 'integer',
        'created_by' => 'integer',
    ];
}

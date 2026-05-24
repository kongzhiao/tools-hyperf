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
        'rule_name',
        'rule_type',
        'conditions',
        'status',
        'sort',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'conditions' => 'array',
        'status' => 'integer',
        'sort' => 'integer',
    ];
}

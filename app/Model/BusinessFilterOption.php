<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

class BusinessFilterOption extends Model
{
    use SoftDeletes;

    protected ?string $table = 'business_filter_options';

    protected array $fillable = [
        'module',
        'type',
        'value',
        'label',
        'status',
        'sort',
        'source_batch',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
    ];
}

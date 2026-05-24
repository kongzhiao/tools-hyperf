<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\Database\Model\SoftDeletes;

class Town extends Model
{
    use SoftDeletes;

    protected ?string $table = 'towns';

    protected array $fillable = [
        'name',
        'code',
        'status',
        'sort',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
        'sort' => 'integer',
    ];
}

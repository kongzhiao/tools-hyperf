<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class YfCategoryQuota extends Model
{
    protected ?string $table = 'yf_category_quotas';

    protected array $fillable = [
        'year',
        'category',
        'quota_amount',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'quota_amount' => 'decimal:2',
    ];
}

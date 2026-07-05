<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;

class EnrollReviewBatch extends Model
{
    public const STATUS_DISPATCHED = '已下放';
    public const STATUS_PARTIAL_RECALLED = '部分收回';
    public const STATUS_RECALLED = '已收回';

    public const MODE_TOWN = 'town';
    public const MODE_MANUAL = 'manual';
    public const MODE_FILTER = 'filter';

    protected ?string $table = 'enroll_review_batches';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'town_names' => 'array',
        'filter_snapshot' => 'array',
        'total_count' => 'integer',
        'created_by' => 'integer',
        'dispatched_at' => 'datetime',
        'recalled_at' => 'datetime',
    ];
}

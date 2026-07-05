<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;

class EnrollReviewItem extends Model
{
    public const STATUS_PENDING = '待填报';
    public const STATUS_FILLED = '已填报';
    public const STATUS_RECALLED = '已收回';

    protected ?string $table = 'enroll_review_items';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'batch_id' => 'integer',
        'ledger_id' => 'integer',
        'submitted_at' => 'datetime',
        'recalled_at' => 'datetime',
    ];
}

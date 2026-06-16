<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;

class EnrollImportBatch extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected ?string $table = 'enroll_import_batches';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'total_rows' => 'integer',
        'success_rows' => 'integer',
        'failed_rows' => 'integer',
        'created_by' => 'integer',
    ];
}

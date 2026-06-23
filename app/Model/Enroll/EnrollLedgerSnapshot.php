<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;

class EnrollLedgerSnapshot extends Model
{
    public const TYPE_BEFORE_IMPORT = 'before_import';
    public const TYPE_AFTER_IMPORT = 'after_import';

    protected ?string $table = 'enroll_ledger_snapshots';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'medical_identity_records' => 'array',
        'subsidy_identity_records' => 'array',
    ];
}

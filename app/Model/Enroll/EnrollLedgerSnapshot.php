<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;

class EnrollLedgerSnapshot extends Model
{
    public const TYPE_BEFORE_IMPORT = 'before_import';
    public const TYPE_AFTER_IMPORT = 'after_import';

    protected ?string $table = 'enroll_ledger_snapshots';

    protected array $encrypts = ['name', 'id_card'];

    protected array $blindIndexes = ['name' => 'name_bidx', 'id_card' => 'id_card_bidx'];

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'medical_identity_records' => 'array',
        'subsidy_identity_records' => 'array',
    ];
}

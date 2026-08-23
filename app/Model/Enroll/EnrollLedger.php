<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;

class EnrollLedger extends Model
{
    protected ?string $table = 'enroll_ledgers';

    protected array $encrypts = ['name', 'id_card'];

    protected array $blindIndexes = ['name' => 'name_bidx', 'id_card' => 'id_card_bidx'];

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'medical_identity_records' => 'array',
        'subsidy_identity_records' => 'array',
        'resident_payment_amount' => 'decimal:2',
        'town_resident_payment_amount' => 'decimal:2',
        'subsidy_amount' => 'decimal:2',
        'tax_first_request_amount' => 'decimal:2',
        'town_submitted_at' => 'datetime',
        'town_last_filled_by' => 'integer',
        'town_last_filled_at' => 'datetime',
        'current_review_batch_id' => 'integer',
    ];
}

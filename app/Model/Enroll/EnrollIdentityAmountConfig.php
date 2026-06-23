<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;
use Hyperf\Database\Model\SoftDeletes;

class EnrollIdentityAmountConfig extends Model
{
    use SoftDeletes;

    protected ?string $table = 'enroll_identity_amount_configs';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'included_identities' => 'array',
        'paid_amount' => 'decimal:2',
        'status' => 'integer',
        'sort' => 'integer',
    ];
}

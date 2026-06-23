<?php

declare(strict_types=1);

namespace App\Model\Enroll;

use App\Model\Model;
use Hyperf\Database\Model\SoftDeletes;

class EnrollConfig extends Model
{
    use SoftDeletes;

    public const TYPE_SUBSIDY = 'subsidy';
    public const TYPE_MEDICAL = 'medical';

    protected ?string $table = 'enroll_configs';

    protected array $guarded = [];

    protected array $casts = [
        'id' => 'integer',
        'year' => 'integer',
        'priority' => 'integer',
        'personal_amount' => 'decimal:2',
        'subsidy_amount' => 'decimal:2',
        'included_identities' => 'array',
        'status' => 'integer',
    ];
}

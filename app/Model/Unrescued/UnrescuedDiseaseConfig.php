<?php

declare(strict_types=1);

namespace App\Model\Unrescued;

use App\Model\Model;
use Hyperf\Database\Model\SoftDeletes;

class UnrescuedDiseaseConfig extends Model
{
    use SoftDeletes;

    protected ?string $table = 'unrescued_disease_configs';

    protected array $fillable = [
        'disease_code',
        'disease_name',
        'status',
        'source_batch',
        'remark',
    ];

    protected array $casts = [
        'id' => 'integer',
        'status' => 'integer',
    ];
}

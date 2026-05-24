<?php

declare(strict_types=1);

namespace App\Model;

class OperationLog extends Model
{
    protected ?string $table = 'operation_logs';

    protected array $fillable = [
        'user_id',
        'username',
        'module',
        'action',
        'target_type',
        'target_id',
        'description',
        'params',
        'ip',
        'user_agent',
        'status',
        'error_message',
    ];

    protected array $casts = [
        'id' => 'integer',
        'user_id' => 'integer',
        'params' => 'array',
    ];
}

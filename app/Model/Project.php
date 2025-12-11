<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Project extends Model
{
    protected ?string $table = 'projects';
    
    protected array $fillable = [
        'code',
        'dec',
    ];
    
    protected array $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
} 
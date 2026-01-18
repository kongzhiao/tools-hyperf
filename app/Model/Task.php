<?php

declare(strict_types=1);

namespace App\Model;

/**
 * @property int $id 
 * @property string $uuid 
 * @property string $title 
 * @property int $uid 
 * @property string $uname 
 * @property string $progress 
 * @property \Carbon\Carbon $created_at 
 * @property \Carbon\Carbon $updated_at 
 */
class Task extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'task';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['uuid', 'title', 'uid', 'uname', 'progress', 'download_url', 'url_at', 'file_size'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'progress' => 'decimal:2'];
}

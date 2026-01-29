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
 * @property string $file_url 
 * @property string $url_at 
 * @property string $file_size 
 * @property int $status 
 * @property \Carbon\Carbon $created_at 
 * @property \Carbon\Carbon $updated_at 
 */
class Task extends Model
{
    /**
     * 任务状态常量
     */
    public const STATUS_FAILED = -2;     // 执行失败
    public const STATUS_CANCELLED = -1;  // 已取消
    public const STATUS_PENDING = 0;     // 待执行
    public const STATUS_RUNNING = 1;     // 执行中
    public const STATUS_COMPLETED = 2;   // 已完成

    /**
     * 状态映射（用于 API 响应）
     */
    public const STATUS_MAP = [
        self::STATUS_FAILED => 'failed',
        self::STATUS_CANCELLED => 'cancelled',
        self::STATUS_PENDING => 'pending',
        self::STATUS_RUNNING => 'processing',
        self::STATUS_COMPLETED => 'completed',
    ];

    /**
     * The table associated with the model.
     */
    protected ?string $table = 'task';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['uuid', 'title', 'uid', 'uname', 'progress', 'file_url', 'url_at', 'file_size', 'status'];

    /**
     * The attributes that should be cast to native types.
     */
    protected array $casts = ['id' => 'integer', 'uid' => 'integer', 'progress' => 'decimal:2', 'status' => 'integer'];
}

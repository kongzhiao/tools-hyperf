<?php

declare(strict_types=1);

namespace App\Job;

use Hyperf\AsyncQueue\Job;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use App\Model\Task;

abstract class AbstractJob extends Job
{
    /**
     * 最大重试次数，默认为 0 表示读取配置
     */
    protected int $maxAttempts = 0;

    /**
     * 业务锁 Key（由 dispatchTask 自动注入）
     */
    public string $lockKey = '';

    /**
     * 获取最大重试次数
     */
    public function getMaxAttempts(): int
    {
        if ($this->maxAttempts > 0) {
            return (int) $this->maxAttempts;
        }

        $container = ApplicationContext::getContainer();
        $retryCount = 0;
        if ($container && $container->has(ConfigInterface::class)) {
            $config = $container->get(ConfigInterface::class);

            // 优先读取 max_attempts 配置
            $retryCount = $config->get('async_queue.default.max_attempts');

            if (is_null($retryCount)) {
                $retrySeconds = $config->get('async_queue.default.retry_seconds');
                if (is_array($retrySeconds)) {
                    $retryCount = count($retrySeconds);
                }
            }
        }

        return (int) ($retryCount ?? 0);
    }

    /**
     * 更新任务进度
     * @param string $uuid 任务唯一标识
     * @param float|int $progress 进度值 (0-100)
     */
    protected function updateProgress(string $uuid, $progress): void
    {
        $this->updateTask($uuid, [
            'progress' => round((float) $progress, 2)
        ]);
    }

    /**
     * 更新任务数据
     */
    protected function updateTask(string $uuid, array $data): void
    {
        Task::where('uuid', $uuid)->update($data);
    }

    /**
     * 释放业务锁（子类在任务完成/失败时调用）
     */
    protected function releaseLock(): void
    {
        if (!empty($this->lockKey)) {
            \App\Service\TaskService::unlock($this->lockKey);
        }
    }
}


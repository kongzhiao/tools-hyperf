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
     * @var array 任务参数
     */
    public array $params = [];

    /**
     * @var string 任务 UUID
     */
    public string $uuid = '';

    /**
     * 最大重试次数，默认为 0 表示读取配置
     */
    protected int $maxAttempts = 0;

    /**
     * 业务锁 Key（由 dispatchTask 自动注入）
     */
    public string $lockKey = '';

    public function __construct(array $params, string $uuid)
    {
        $this->params = $params;
        $this->uuid = $uuid;
    }

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
     * 标记任务开始执行
     */
    protected function startTask(): void
    {
        $this->updateTask($this->uuid, [
            'status' => Task::STATUS_RUNNING,
            'progress' => 0.00,
            'failure_reason' => null
        ]);
    }

    /**
     * 完成任务
     * @param string $fileUrl 文件下载地址
     * @param float $fileSizeMb 文件大小 (MB)
     */
    protected function finishTask(string $fileUrl, float $fileSizeMb): void
    {
        $this->updateTask($this->uuid, [
            'progress' => 100.00,
            'file_url' => $fileUrl,
            'url_at' => date('Y-m-d H:i:s'),
            'file_size' => $fileSizeMb,
            'status' => Task::STATUS_COMPLETED,
            'failure_reason' => null
        ]);
        $this->releaseLock();
    }

    /**
     * 标记任务失败
     */
    protected function failTask(\Throwable $e, ?string $customMsg = null): void
    {
        $container = ApplicationContext::getContainer();
        $logger = $container->get(\Hyperf\Logger\LoggerFactory::class)->get('default');

        $msg = $customMsg ?: "Task Execution Failed";
        $logger->error("{$msg} [{$this->uuid}]: " . $e->getMessage() . "\n" . $e->getTraceAsString());

        $this->updateTask($this->uuid, [
            'status' => Task::STATUS_FAILED,
            'failure_reason' => $this->buildFailureReason($e, $customMsg)
        ]);
        $this->releaseLock();
    }

    /**
     * 生成给任务中心展示的失败原因，日志里仍保留完整堆栈。
     */
    protected function buildFailureReason(\Throwable $e, ?string $customMsg = null): string
    {
        $reason = trim(($customMsg ? $customMsg . '：' : '') . $e->getMessage());
        if ($reason === '') {
            $reason = '任务执行失败，请联系管理员查看服务端日志';
        }

        return mb_substr($reason, 0, 1000);
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

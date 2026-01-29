<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Task;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;

/**
 * 任务服务
 */
class TaskService
{
    protected Redis $redis;
    protected DriverFactory $driverFactory;

    public function __construct(Redis $redis, DriverFactory $driverFactory)
    {
        $this->redis = $redis;
        $this->driverFactory = $driverFactory;
    }

    /**
     * 尝试获取业务锁
     * @param string $key 锁的 Key
     * @param int $ttl 锁的过期时间（秒），默认 2 小时
     * @return bool 是否获取成功
     */
    public function tryLock(string $key, int $ttl = 7200): bool
    {
        return (bool) $this->redis->set($key, '1', ['NX', 'EX' => $ttl]);
    }

    /**
     * 释放锁
     * @param string $key 锁的 Key
     */
    public function releaseLock(string $key): void
    {
        $this->redis->del($key);
    }

    /**
     * 创建任务并投递到队列
     * 
     * @param string $title 任务标题
     * @param int $uid 用户ID
     * @param string $uname 用户名
     * @param string $jobClass Job 类名
     * @param array $jobParams Job 构造函数参数，uuid 会自动注入为第二个参数
     * @param string|null $lockKey 锁的 Key（可选，传入则自动校验锁，并注入到 Job）
     * @param int $lockTtl 锁的过期时间（秒），默认 2 小时
     * @param string $queueName 队列名称，默认 'default'
     * @return string|false 成功返回任务 UUID，锁被占用返回 false
     */
    public function dispatchTask(
        string $title,
        int $uid,
        string $uname,
        string $jobClass,
        array $jobParams = [],
        ?string $lockKey = null,
        int $lockTtl = 7200,
        string $queueName = 'default'
    ): string|false {
        // 1. 如果传了 lockKey，先尝试获取锁
        if ($lockKey !== null && !$this->tryLock($lockKey, $lockTtl)) {
            return false;
        }

        // 2. 生成任务 UUID
        $uuid = date('YmdHis') . str_pad((string) $uid, 10, '0', STR_PAD_LEFT) . mt_rand(10000, 99999);

        // 3. 创建任务记录
        Task::create([
            'uuid' => $uuid,
            'title' => $title,
            'uid' => $uid,
            'uname' => $uname,
            'progress' => 0.00,
            'status' => Task::STATUS_PENDING
        ]);

        // 4. 实例化 Job
        // Job 构造函数签名: __construct(array $params, string $uuid)
        array_splice($jobParams, 1, 0, [$uuid]);
        $job = new $jobClass(...$jobParams);

        // 5. 如果有锁，注入 lockKey 到 Job
        if ($lockKey !== null && property_exists($job, 'lockKey')) {
            $job->lockKey = $lockKey;
        }

        // 6. 投递到队列
        $driver = $this->driverFactory->get($queueName);
        $driver->push($job);

        return $uuid;
    }

    /**
     * 静态方法：获取 TaskService 实例
     */
    public static function instance(): self
    {
        return ApplicationContext::getContainer()->get(self::class);
    }

    /**
     * 静态方法：释放锁
     */
    public static function unlock(string $key): void
    {
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $redis->del($key);
    }
}

<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;

class LoginAttemptService
{
    private const FAILURE_WINDOW = 900;

    private const FAILURE_LIMIT = 5;

    private const LOCK_DURATIONS = [600, 1800, 3600];

    private function redis(): Redis
    {
        return ApplicationContext::getContainer()->get(Redis::class);
    }

    public function status(string $username): array
    {
        $ttl = (int) $this->redis()->ttl($this->lockKey($username));
        return [
            'locked' => $ttl > 0,
            'remaining_seconds' => max(0, $ttl),
        ];
    }

    public function registerFailure(string $username): array
    {
        $redis = $this->redis();
        $failureKey = $this->failureKey($username);
        $failures = (int) $redis->incr($failureKey);
        if ($failures === 1) {
            $redis->expire($failureKey, self::FAILURE_WINDOW);
        }

        $result = [
            'failure_count' => $failures,
            'locked' => false,
            'remaining_seconds' => 0,
        ];

        if ($failures < self::FAILURE_LIMIT) {
            return $result;
        }

        $levelKey = $this->levelKey($username);
        $level = min(count(self::LOCK_DURATIONS), (int) $redis->incr($levelKey));
        $redis->expire($levelKey, 86400);
        $duration = self::LOCK_DURATIONS[$level - 1];
        $redis->setex($this->lockKey($username), $duration, (string) time());
        $redis->del($failureKey);

        $result['locked'] = true;
        $result['remaining_seconds'] = $duration;
        $result['lock_level'] = $level;
        return $result;
    }

    public function clear(string $username, bool $clearLevel = true): void
    {
        $keys = [$this->failureKey($username), $this->lockKey($username)];
        if ($clearLevel) {
            $keys[] = $this->levelKey($username);
        }
        $this->redis()->del(...$keys);
    }

    private function normalized(string $username): string
    {
        $username = trim($username);
        return function_exists('mb_strtolower') ? mb_strtolower($username, 'UTF-8') : strtolower($username);
    }

    private function failureKey(string $username): string
    {
        return 'auth:login:failures:' . hash('sha256', $this->normalized($username));
    }

    private function lockKey(string $username): string
    {
        return 'auth:login:lock:' . hash('sha256', $this->normalized($username));
    }

    private function levelKey(string $username): string
    {
        return 'auth:login:lock-level:' . hash('sha256', $this->normalized($username));
    }
}

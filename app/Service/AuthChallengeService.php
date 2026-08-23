<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;

class AuthChallengeService
{
    private function redis(): Redis
    {
        return ApplicationContext::getContainer()->get(Redis::class);
    }

    public function create(int $userId, string $type, array $extra = []): string
    {
        $token = bin2hex(random_bytes(32));
        $data = array_merge($extra, [
            'user_id' => $userId,
            'type' => $type,
            'attempts' => 0,
            'created_at' => time(),
        ]);
        $this->save($token, $data);
        return $token;
    }

    public function get(string $token, ?string $expectedType = null): ?array
    {
        if ($token === '') {
            return null;
        }

        $raw = $this->redis()->get($this->key($token));
        $data = $raw ? json_decode((string) $raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        if ($expectedType !== null && ($data['type'] ?? null) !== $expectedType) {
            return null;
        }

        return $data;
    }

    public function save(string $token, array $data): void
    {
        $ttl = (int) config('security.auth.challenge_ttl', 600);
        $this->redis()->setex(
            $this->key($token),
            $ttl,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public function registerFailure(string $token, array $data, int $limit = 5): int
    {
        $attempts = (int) ($data['attempts'] ?? 0) + 1;
        if ($attempts >= $limit) {
            $this->delete($token);
            return $attempts;
        }

        $data['attempts'] = $attempts;
        $this->save($token, $data);
        return $attempts;
    }

    public function delete(string $token): void
    {
        if ($token !== '') {
            $this->redis()->del($this->key($token));
        }
    }

    private function key(string $token): string
    {
        return 'auth:challenge:' . hash('sha256', $token);
    }
}

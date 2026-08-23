<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use RuntimeException;
use stdClass;

class AuthSessionService
{
    private function redis(): Redis
    {
        return ApplicationContext::getContainer()->get(Redis::class);
    }

    public function create(User $user): array
    {
        $now = time();
        $sid = bin2hex(random_bytes(32));
        $sessionVersion = max(1, (int) ($user->session_version ?? 1));
        $session = [
            'sid' => $sid,
            'user_id' => (int) $user->id,
            'session_version' => $sessionVersion,
            'created_at' => $now,
            'last_touched_at' => $now,
        ];
        $ttl = $this->sessionTtl();
        $this->redis()->setex($this->sessionKey($sid), $ttl, (string) json_encode($session));

        $payload = [
            'user_id' => (int) $user->id,
            'username' => (string) $user->username,
            'sid' => $sid,
            'sv' => $sessionVersion,
            'iat' => $now,
            'reauth_until' => $now + (int) config('security.auth.reauth_token_ttl', 2592000),
        ];

        return [
            'token' => JWT::encode($payload, $this->jwtSecret(), 'HS256'),
            'sid' => $sid,
            'expires_in' => $ttl,
        ];
    }

    public function decode(string $token): stdClass
    {
        if ($token === '') {
            throw new RuntimeException('Token不能为空');
        }

        return JWT::decode($token, new Key($this->jwtSecret(), 'HS256'));
    }

    public function get(string $sid): ?array
    {
        if ($sid === '') {
            return null;
        }
        $raw = $this->redis()->get($this->sessionKey($sid));
        $session = $raw ? json_decode((string) $raw, true) : null;
        return is_array($session) ? $session : null;
    }

    public function touch(array $session): void
    {
        $now = time();
        $lastTouched = (int) ($session['last_touched_at'] ?? 0);
        $interval = (int) config('security.auth.session_touch_interval', 600);
        if ($lastTouched > 0 && $now - $lastTouched < $interval) {
            return;
        }

        $session['last_touched_at'] = $now;
        $this->redis()->setex(
            $this->sessionKey((string) $session['sid']),
            $this->sessionTtl(),
            (string) json_encode($session)
        );
    }

    public function revoke(string $sid): void
    {
        if ($sid === '') {
            return;
        }

        $this->redis()->del($this->sessionKey($sid));
        $this->redis()->setex(
            $this->revokedKey($sid),
            (int) config('security.auth.reauth_token_ttl', 2592000),
            '1'
        );
    }

    public function isRevoked(string $sid): bool
    {
        return $sid !== '' && (bool) $this->redis()->exists($this->revokedKey($sid));
    }

    public function canReauthenticate(stdClass $payload): bool
    {
        $sid = isset($payload->sid) ? (string) $payload->sid : '';
        return isset($payload->user_id, $payload->sv, $payload->reauth_until)
            && (int) $payload->reauth_until >= time()
            && !$this->isRevoked($sid);
    }

    public function revokeAll(User $user): void
    {
        $user->session_version = max(1, (int) ($user->session_version ?? 1)) + 1;
        $user->save();
        $this->clearUserCache((int) $user->id);
    }

    public function clearUserCache(int $userId): void
    {
        $this->redis()->del('user:cache:' . $userId);
    }

    public function sessionTtl(): int
    {
        return (int) config('security.auth.session_ttl', 86400);
    }

    private function sessionKey(string $sid): string
    {
        return 'auth:session:' . $sid;
    }

    private function revokedKey(string $sid): string
    {
        return 'auth:session:revoked:' . $sid;
    }

    private function jwtSecret(): string
    {
        $secret = (string) env('JWT_SECRET', 'your-secret-key');
        if ($secret === '') {
            throw new RuntimeException('JWT密钥未配置');
        }
        return $secret;
    }
}

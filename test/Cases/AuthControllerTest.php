<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Model\OperationLog;
use App\Model\User;
use App\Service\AuthChallengeService;
use App\Service\AuthSessionService;
use App\Service\LoginAttemptService;
use App\Service\TotpService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class AuthControllerTest extends HttpTestCase
{
    private ?User $loginUser = null;

    private array $sessionIds = [];

    protected function tearDown(): void
    {
        if ($this->loginUser !== null) {
            $container = ApplicationContext::getContainer();
            $redis = $container->get(Redis::class);
            foreach ($this->sessionIds as $sid) {
                $redis->del('auth:session:' . $sid, 'auth:session:revoked:' . $sid);
            }
            $redis->del('user:cache:' . $this->loginUser->id);
            $container->get(LoginAttemptService::class)->clear((string) $this->loginUser->username);
            OperationLog::query()->where('username', (string) $this->loginUser->username)->delete();
            $this->loginUser->delete();
        }

        parent::tearDown();
    }

    public function testFailedLoginIsAuditedWithMaskedPassword(): void
    {
        $this->createLoginUser();
        $wrongPassword = 'Wrong-pass!123';

        $json = $this->post('/api/login', [
            'username' => $this->loginUser->username,
            'password' => $wrongPassword,
        ])->json();

        self::assertSame(401, $json['code'] ?? null);
        $log = OperationLog::query()
            ->where('username', (string) $this->loginUser->username)
            ->where('action', 'login')
            ->latest('id')
            ->first();
        self::assertNotNull($log);
        self::assertSame('failed', $log->status);
        self::assertSame('Wr***23', $log->params['password'] ?? null);
        self::assertStringNotContainsString($wrongPassword, json_encode($log->params));
    }

    public function testLoginAuthenticatedRequestAndLogoutRemainUsable(): void
    {
        $password = 'Login-test!123';
        $this->createLoginUser($password);

        $login = $this->post('/api/login', [
            'username' => $this->loginUser->username,
            'password' => $password,
        ])->json();
        self::assertSame(0, $login['code'] ?? null);
        $token = (string) ($login['data']['token'] ?? '');
        self::assertNotSame('', $token);

        $sessionService = ApplicationContext::getContainer()->get(AuthSessionService::class);
        $payload = $sessionService->decode($token);
        $sid = (string) $payload->sid;
        $this->sessionIds[] = $sid;
        self::assertNotNull($sessionService->get($sid));

        $headers = ['Authorization' => 'Bearer ' . $token];
        $info = $this->get('/api/user/info', [], $headers)->json();
        self::assertSame(0, $info['code'] ?? null);
        self::assertSame((int) $this->loginUser->id, (int) ($info['data']['id'] ?? 0));

        $logout = $this->post('/api/logout', [], $headers)->json();
        self::assertSame(0, $logout['code'] ?? null);
        self::assertNull($sessionService->get($sid));

        $logoutLog = OperationLog::query()
            ->where('user_id', (int) $this->loginUser->id)
            ->where('action', 'logout')
            ->latest('id')
            ->first();
        self::assertNotNull($logoutLog);
        self::assertSame((string) $this->loginUser->username, (string) $logoutLog->username);
    }

    public function testTotpBindingAlwaysRejectsInvalidCode(): void
    {
        $password = 'Login-test!123';
        $this->createLoginUser($password);

        $login = $this->post('/api/login', [
            'username' => $this->loginUser->username,
            'password' => $password,
        ])->json();
        self::assertSame(0, $login['code'] ?? null);

        $token = (string) ($login['data']['token'] ?? '');
        self::assertNotSame('', $token);
        $payload = ApplicationContext::getContainer()->get(AuthSessionService::class)->decode($token);
        $this->sessionIds[] = (string) $payload->sid;
        $headers = ['Authorization' => 'Bearer ' . $token];

        $setup = $this->post('/api/user/totp/setup', [], $headers)->json();
        self::assertSame(0, $setup['code'] ?? null);
        $challengeToken = (string) ($setup['data']['challenge_token'] ?? '');
        self::assertNotSame('', $challengeToken);
        $invalidCode = $this->invalidTotpCode((string) ($setup['data']['secret'] ?? ''));

        try {
            $bind = $this->post('/api/user/totp/bind', [
                'challenge_token' => $challengeToken,
                'code' => $invalidCode,
            ], $headers)->json();
            self::assertSame(400, $bind['code'] ?? null);

            $this->loginUser->refresh();
            self::assertFalse($this->loginUser->isTotpBound());
        } finally {
            ApplicationContext::getContainer()->get(AuthChallengeService::class)->delete($challengeToken);
        }
    }

    public function testLoginTotpBindingAlwaysRejectsInvalidCode(): void
    {
        $this->createLoginUser();
        $challengeService = ApplicationContext::getContainer()->get(AuthChallengeService::class);
        $challengeToken = $challengeService->create((int) $this->loginUser->id, 'login_bind');

        try {
            $setup = $this->post('/api/auth/totp/login-setup', [
                'challenge_token' => $challengeToken,
            ])->json();
            self::assertSame(0, $setup['code'] ?? null);
            $invalidCode = $this->invalidTotpCode((string) ($setup['data']['secret'] ?? ''));

            $bind = $this->post('/api/auth/totp/login-bind', [
                'challenge_token' => $challengeToken,
                'code' => $invalidCode,
            ])->json();
            self::assertSame(400, $bind['code'] ?? null);

            $this->loginUser->refresh();
            self::assertFalse($this->loginUser->isTotpBound());
        } finally {
            $challengeService->delete($challengeToken);
        }
    }

    private function invalidTotpCode(string $secret): string
    {
        self::assertNotSame('', $secret);
        $totpService = ApplicationContext::getContainer()->get(TotpService::class);
        for ($value = 0; $value <= 999999; ++$value) {
            $code = str_pad((string) $value, 6, '0', STR_PAD_LEFT);
            if (!$totpService->verify($secret, $code)) {
                return $code;
            }
        }

        self::fail('无法构造错误的6位动态验证码');
    }

    private function createLoginUser(string $password = 'Login-test!123'): void
    {
        $this->loginUser = User::create([
            'username' => 'auth-http-test-' . bin2hex(random_bytes(8)),
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => '认证接口测试账号',
            'totp_required' => true,
            'session_version' => 1,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Model\OperationLog;
use App\Model\User;
use HyperfTest\HttpTestCase;

/**
 * @internal
 * @coversNothing
 */
class UserControllerTest extends HttpTestCase
{
    private array $createdUsernames = [];

    protected function tearDown(): void
    {
        if ($this->createdUsernames !== []) {
            $users = User::query()->whereIn('username', $this->createdUsernames)->get();
            foreach ($users as $user) {
                $user->roles()->detach();
                $user->delete();
            }
            OperationLog::query()->whereIn('target_id', $users->pluck('id')->map(static fn ($id) => (string) $id))->delete();
        }

        parent::tearDown();
    }

    public function testNewUserDefaultsToTotpRequiredAndPasswordIsHidden(): void
    {
        $username = 'user-http-test-' . bin2hex(random_bytes(8));
        $this->createdUsernames[] = $username;
        $password = 'Create-user!123';

        $json = $this->post('/api/users', [
            'username' => $username,
            'password' => $password,
            'nickname' => '新增用户测试',
        ], $this->authenticatedHeaders())->json();

        self::assertSame(0, $json['code'] ?? null);
        self::assertTrue((bool) ($json['data']['totp_required'] ?? false));
        self::assertArrayNotHasKey('password', $json['data'] ?? []);
        self::assertArrayNotHasKey('totp_secret', $json['data'] ?? []);

        $user = User::query()->where('username', $username)->first();
        self::assertNotNull($user);
        self::assertTrue((bool) $user->totp_required);
        self::assertTrue(password_verify($password, (string) $user->password));
    }

    public function testWeakPasswordIsRejectedWithoutCreatingUser(): void
    {
        $username = 'weak-user-test-' . bin2hex(random_bytes(8));
        $this->createdUsernames[] = $username;

        $json = $this->post('/api/users', [
            'username' => $username,
            'password' => 'weak1234',
        ], $this->authenticatedHeaders())->json();

        self::assertSame(400, $json['code'] ?? null);
        self::assertFalse(User::query()->where('username', $username)->exists());
    }
}

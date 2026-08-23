<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace HyperfTest;

use App\Model\User;
use App\Service\AuthSessionService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Redis\Redis;
use Hyperf\Testing\TestCase;
use Hyperf\Testing\Client;

/**
 * @method get($uri, $data = [], $headers = [])
 * @method post($uri, $data = [], $headers = [])
 * @method json($uri, $data = [], $headers = [])
 * @method file($uri, $data = [], $headers = [])
 * @method request($method, $path, $options = [])
 */
abstract class HttpTestCase extends TestCase
{
    /**
     * @var Client
     */
    protected $client;

    private ?User $authenticatedUser = null;

    private ?string $authenticatedSessionId = null;

    private ?string $authenticatedToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = make(Client::class);
    }

    protected function tearDown(): void
    {
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        if ($this->authenticatedSessionId !== null) {
            $redis->del(
                'auth:session:' . $this->authenticatedSessionId,
                'auth:session:revoked:' . $this->authenticatedSessionId
            );
        }
        if ($this->authenticatedUser !== null) {
            $redis->del('user:cache:' . $this->authenticatedUser->id);
            $this->authenticatedUser->delete();
        }

        parent::tearDown();
    }

    protected function authenticatedHeaders(): array
    {
        if ($this->authenticatedUser === null) {
            $suffix = bin2hex(random_bytes(8));
            $this->authenticatedUser = User::create([
                'username' => 'http-test-' . $suffix,
                'password' => password_hash('Test-only!123', PASSWORD_DEFAULT),
                'nickname' => 'HTTP测试账号',
            ]);
            $sessionService = ApplicationContext::getContainer()->get(AuthSessionService::class);
            $session = $sessionService->create($this->authenticatedUser);
            $payload = $sessionService->decode($session['token']);
            $this->authenticatedSessionId = (string) $payload->sid;
            $this->authenticatedToken = $session['token'];
        }

        return ['Authorization' => 'Bearer ' . $this->authenticatedToken];
    }

    public function __call($name, $arguments)
    {
        return $this->client->{$name}(...$arguments);
    }
}

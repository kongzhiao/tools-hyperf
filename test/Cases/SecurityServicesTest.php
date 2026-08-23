<?php

declare(strict_types=1);

namespace HyperfTest\Cases;

use App\Command\EncryptSensitiveDataCommand;
use App\Model\InsuranceData;
use App\Model\User;
use App\Service\AuthSessionService;
use App\Service\LoginAttemptService;
use App\Service\OperationLogService;
use App\Service\PasswordPolicyService;
use App\Service\SensitiveDataCipher;
use App\Service\TotpService;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\Redis;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SecurityServicesTest extends TestCase
{
    private SensitiveDataCipher $cipher;

    private SensitiveDataCipher $originalCipher;

    private string $publicKeyPath;

    private string $privateKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $key = openssl_pkey_new([
            'private_key_bits' => 1024,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($key);
        self::assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);

        $this->publicKeyPath = (string) tempnam(sys_get_temp_dir(), 'field-public-');
        $this->privateKeyPath = (string) tempnam(sys_get_temp_dir(), 'field-private-');
        file_put_contents($this->publicKeyPath, (string) $details['key']);
        file_put_contents($this->privateKeyPath, $privateKey);
        chmod($this->privateKeyPath, 0600);

        $this->cipher = new SensitiveDataCipher(
            $this->publicKeyPath,
            $this->privateKeyPath,
            'unit-test-blind-index-key'
        );

        $container = ApplicationContext::getContainer();
        $this->originalCipher = $container->get(SensitiveDataCipher::class);
        $container->set(SensitiveDataCipher::class, $this->cipher);
    }

    protected function tearDown(): void
    {
        ApplicationContext::getContainer()->set(SensitiveDataCipher::class, $this->originalCipher);
        @unlink($this->publicKeyPath);
        @unlink($this->privateKeyPath);
        parent::tearDown();
    }

    public function testSensitiveCipherRoundTripAndEmptyValues(): void
    {
        $first = $this->cipher->encrypt('测试敏感值');
        $second = $this->cipher->encrypt('测试敏感值');

        self::assertNotSame($first, $second);
        self::assertStringStartsWith('ENC1:', (string) $first);
        self::assertSame('测试敏感值', $this->cipher->decrypt($first));
        self::assertNull($this->cipher->encrypt(null));
        self::assertSame('', $this->cipher->encrypt(''));
        self::assertSame(
            $this->cipher->blindIndex('  Example  '),
            $this->cipher->blindIndex('example')
        );
    }

    public function testModelEncryptsConfiguredAttributesAndKeepsReadableValue(): void
    {
        $model = new InsuranceData();
        $model->name = '模型测试姓名';
        $model->id_number = '500000000000000000';

        self::assertStringStartsWith('ENC1:', (string) $model->getAttributes()['name']);
        self::assertStringStartsWith('ENC1:', (string) $model->getAttributes()['id_number']);
        self::assertSame('模型测试姓名', $model->name);
        self::assertSame('500000000000000000', $model->id_number);
        self::assertSame('模型测试姓名', $model->toArray()['name']);
        self::assertSame('500000000000000000', $model->toArray()['id_number']);
        self::assertStringNotContainsString('ENC1:', $model->toJson());
        self::assertSame(64, strlen((string) $model->getAttributes()['name_bidx']));
        self::assertSame(64, strlen((string) $model->getAttributes()['id_number_bidx']));
    }

    public function testEncryptedModelPersistsCiphertextAndSupportsExactLookup(): void
    {
        $identifier = 'test-id-' . bin2hex(random_bytes(12));
        $record = null;

        try {
            $record = InsuranceData::create([
                'year' => 2999,
                'name' => '持久化加密测试',
                'id_type' => '测试证件',
                'id_number' => $identifier,
                'person_number' => null,
            ]);

            $stored = (array) Db::table('insurance_data')->where('id', (int) $record->id)->first();
            self::assertStringStartsWith('ENC1:', (string) ($stored['name'] ?? ''));
            self::assertStringStartsWith('ENC1:', (string) ($stored['id_number'] ?? ''));
            self::assertSame($this->cipher->blindIndex($identifier), $stored['id_number_bidx'] ?? null);
            self::assertNull($stored['person_number'] ?? null);

            $found = InsuranceData::query()->whereBlind('id_number', $identifier)->first();
            self::assertNotNull($found);
            self::assertSame('持久化加密测试', $found->name);
            self::assertSame($identifier, $found->id_number);
            self::assertSame((int) $record->id, (int) $found->id);
            self::assertSame('持久化加密测试', $found->toArray()['name']);
            self::assertSame($identifier, $found->toArray()['id_number']);
            self::assertStringNotContainsString('ENC1:', $found->toJson());
        } finally {
            if ($record !== null) {
                Db::table('insurance_data')->where('id', (int) $record->id)->delete();
            }
        }
    }

    public function testBulkEncryptionCommandEncryptsAndVerifiesOneBatch(): void
    {
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $checkpointKey = 'data:encrypt-sensitive:checkpoint:statistics_summery';
        $recordId = null;
        $name = '批量加密测试';
        $identifier = 'test-summary-' . bin2hex(random_bytes(8));
        $personNumber = 'test-person-' . bin2hex(random_bytes(8));

        try {
            $recordId = (int) Db::table('statistics_summery')->insertGetId([
                'project_code' => 'TEST',
                'data_type' => '测试',
                'name' => $name,
                'id_number' => $identifier,
                'person_number' => $personNumber,
                'payment_category' => '测试',
            ]);
            $redis->set($checkpointKey, (string) ($recordId - 1));

            $command = ApplicationContext::getContainer()->get(EncryptSensitiveDataCommand::class);
            $tester = new CommandTester($command);
            $exitCode = $tester->execute([
                '--table' => 'statistics_summery',
                '--chunk' => 500,
                '--resume' => true,
                '--verify' => true,
            ]);

            self::assertSame(0, $exitCode, $tester->getDisplay());
            self::assertStringContainsString('总进度 100.00%', $tester->getDisplay());

            $stored = (array) Db::table('statistics_summery')->where('id', $recordId)->first();
            self::assertStringStartsWith('ENC1:', (string) ($stored['name'] ?? ''));
            self::assertStringStartsWith('ENC1:', (string) ($stored['id_number'] ?? ''));
            self::assertStringStartsWith('ENC1:', (string) ($stored['person_number'] ?? ''));
            self::assertSame('TEST', $stored['project_code'] ?? null);
            self::assertSame('测试', $stored['data_type'] ?? null);
            self::assertSame('测试', $stored['payment_category'] ?? null);
            self::assertSame($name, $this->originalCipher->decrypt((string) $stored['name']));
            self::assertSame($identifier, $this->originalCipher->decrypt((string) $stored['id_number']));
            self::assertSame($personNumber, $this->originalCipher->decrypt((string) $stored['person_number']));
            self::assertSame($this->originalCipher->blindIndex($name), $stored['name_bidx'] ?? null);
            self::assertSame($this->originalCipher->blindIndex($identifier), $stored['id_number_bidx'] ?? null);
        } finally {
            if ($recordId !== null) {
                Db::table('statistics_summery')->where('id', $recordId)->delete();
            }
            $redis->del($checkpointKey);
        }
    }

    public function testCiphertextFitsReservedDatabaseLengths(): void
    {
        $fourByteCharacter = "\u{20BB7}";

        self::assertLessThanOrEqual(3072, strlen((string) $this->cipher->encrypt(str_repeat($fourByteCharacter, 255))));
        self::assertLessThanOrEqual(2048, strlen((string) $this->cipher->encrypt(str_repeat($fourByteCharacter, 100))));
        self::assertLessThanOrEqual(1024, strlen((string) $this->cipher->encrypt(str_repeat($fourByteCharacter, 80))));
        self::assertLessThanOrEqual(1024, strlen((string) $this->cipher->encrypt(str_repeat($fourByteCharacter, 50))));
        self::assertLessThanOrEqual(512, strlen((string) $this->cipher->encrypt(str_repeat('1', 32))));
    }

    public function testTotpMatchesRfc6238VectorUsingSixDigits(): void
    {
        $service = new TotpService($this->cipher);
        self::assertTrue($service->verifyAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59, 0));
        self::assertFalse($service->verifyAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287083', 59, 0));
    }

    public function testTotpVerificationUsesIndependentSecureDefaultSwitch(): void
    {
        $config = ApplicationContext::getContainer()->get(ConfigInterface::class);
        $original = $config->get('security.totp.verification_enabled', true);
        $service = new TotpService($this->cipher);

        try {
            $config->set('security.totp.verification_enabled', true);
            self::assertTrue($service->isVerificationEnabled());

            $config->set('security.totp.verification_enabled', false);
            self::assertFalse($service->isVerificationEnabled());
        } finally {
            $config->set('security.totp.verification_enabled', $original);
        }
    }

    public function testPasswordPolicyAndAuditMask(): void
    {
        $policy = new PasswordPolicyService();
        self::assertNull($policy->validate('Abcdef!1'));
        self::assertNotNull($policy->validate('Abc!1'));
        self::assertNotNull($policy->validate('12345678!'));
        self::assertNotNull($policy->validate('Abcdefg1'));

        $method = new \ReflectionMethod(OperationLogService::class, 'maskPassword');
        $method->setAccessible(true);
        $service = new OperationLogService();
        self::assertSame('Ab***!1', $method->invoke($service, 'Abcdef!1'));
        self::assertSame('***', $method->invoke($service, 'A!1'));
    }

    public function testAccountLockEscalatesAndCanBeCleared(): void
    {
        $service = ApplicationContext::getContainer()->get(LoginAttemptService::class);
        $username = 'security-test-' . bin2hex(random_bytes(8));

        try {
            for ($i = 1; $i <= 5; ++$i) {
                $firstLock = $service->registerFailure($username);
            }
            self::assertTrue($firstLock['locked']);
            self::assertSame(600, $firstLock['remaining_seconds']);

            $service->clear($username, false);
            for ($i = 1; $i <= 5; ++$i) {
                $secondLock = $service->registerFailure($username);
            }
            self::assertTrue($secondLock['locked']);
            self::assertSame(1800, $secondLock['remaining_seconds']);
        } finally {
            $service->clear($username);
        }
    }

    public function testRedisBackedSessionCanBeCreatedAndRevoked(): void
    {
        $service = ApplicationContext::getContainer()->get(AuthSessionService::class);
        $user = new User();
        $user->setAttribute('id', 987654321);
        $user->setAttribute('username', 'session-test');
        $user->setAttribute('session_version', 1);

        $session = $service->create($user);
        $payload = $service->decode($session['token']);
        $sid = (string) $payload->sid;

        try {
            self::assertSame(987654321, (int) $payload->user_id);
            self::assertNotNull($service->get($sid));
            $service->revoke($sid);
            self::assertNull($service->get($sid));
            self::assertTrue($service->isRevoked($sid));
        } finally {
            $redis = ApplicationContext::getContainer()->get(Redis::class);
            $redis->del('auth:session:' . $sid, 'auth:session:revoked:' . $sid);
        }
    }
}

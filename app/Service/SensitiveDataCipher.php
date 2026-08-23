<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class SensitiveDataCipher
{
    private const PREFIX = 'ENC1';

    private ?string $publicKeyPath;

    private ?string $privateKeyPath;

    private ?string $blindIndexKey;

    private array $keyFileCache = [];

    private $publicKey = null;

    private $privateKey = null;

    private ?string $resolvedBlindIndexKey = null;

    private ?string $cachedKeyId = null;

    public function __construct(
        ?string $publicKeyPath = null,
        ?string $privateKeyPath = null,
        ?string $blindIndexKey = null
    ) {
        $this->publicKeyPath = $publicKeyPath;
        $this->privateKeyPath = $privateKeyPath;
        $this->blindIndexKey = $blindIndexKey;
    }

    public function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || $this->isEncrypted($value)) {
            return $value;
        }

        $dataKey = random_bytes(32);
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $dataKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('敏感数据加密失败');
        }

        $wrappedKey = '';
        $publicKey = $this->publicKey();
        if ($publicKey === false || !openssl_public_encrypt($dataKey, $wrappedKey, $publicKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('敏感数据密钥封装失败');
        }

        $payload = json_encode([
            'k' => base64_encode($wrappedKey),
            'i' => base64_encode($iv),
            't' => base64_encode($tag),
            'c' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('敏感数据密文编码失败');
        }

        return self::PREFIX . ':' . $this->keyId() . ':' . base64_encode($payload);
    }

    public function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !$this->isEncrypted($value)) {
            return $value;
        }

        $parts = explode(':', $value, 3);
        if (count($parts) !== 3) {
            throw new RuntimeException('敏感数据密文格式无效');
        }

        $payloadJson = base64_decode($parts[2], true);
        $payload = $payloadJson === false ? null : json_decode($payloadJson, true);
        if (!is_array($payload)) {
            throw new RuntimeException('敏感数据密文载荷无效');
        }

        $wrappedKey = $this->decodePayloadPart($payload, 'k');
        $iv = $this->decodePayloadPart($payload, 'i');
        $tag = $this->decodePayloadPart($payload, 't');
        $ciphertext = $this->decodePayloadPart($payload, 'c');

        $dataKey = '';
        $privateKey = $this->privateKey();
        if ($privateKey === false || !openssl_private_decrypt($wrappedKey, $dataKey, $privateKey, OPENSSL_PKCS1_OAEP_PADDING)) {
            throw new RuntimeException('敏感数据密钥解封失败');
        }

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $dataKey, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('敏感数据解密失败');
        }

        return $plaintext;
    }

    public function blindIndex(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return hash_hmac('sha256', $this->normalizeForIndex($value), $this->resolvedBlindIndexKey());
    }

    public function isEncrypted(string $value): bool
    {
        return strncmp($value, self::PREFIX . ':', strlen(self::PREFIX) + 1) === 0;
    }

    private function normalizeForIndex(string $value): string
    {
        $value = trim($value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function keyId(): string
    {
        return $this->cachedKeyId ??= substr(
            hash('sha256', $this->readKeyFile($this->getPublicKeyPath(), '公钥')),
            0,
            12
        );
    }

    private function getPublicKeyPath(): string
    {
        return $this->publicKeyPath ?? (string) config('security.field_encryption.public_key_path');
    }

    private function getPrivateKeyPath(): string
    {
        return $this->privateKeyPath ?? (string) config('security.field_encryption.private_key_path');
    }

    private function readKeyFile(string $path, string $label): string
    {
        if (isset($this->keyFileCache[$path])) {
            return $this->keyFileCache[$path];
        }

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException($label . '文件不存在或不可读');
        }

        $content = file_get_contents($path);
        if ($content === false || trim($content) === '') {
            throw new RuntimeException($label . '文件无效');
        }

        return $this->keyFileCache[$path] = $content;
    }

    private function publicKey()
    {
        if ($this->publicKey !== null) {
            return $this->publicKey;
        }

        $this->publicKey = openssl_pkey_get_public($this->readKeyFile($this->getPublicKeyPath(), '公钥'));
        if ($this->publicKey === false) {
            throw new RuntimeException('公钥文件无效');
        }

        return $this->publicKey;
    }

    private function privateKey()
    {
        if ($this->privateKey !== null) {
            return $this->privateKey;
        }

        $this->privateKey = openssl_pkey_get_private($this->readKeyFile($this->getPrivateKeyPath(), '私钥'));
        if ($this->privateKey === false) {
            throw new RuntimeException('私钥文件无效');
        }

        return $this->privateKey;
    }

    private function resolvedBlindIndexKey(): string
    {
        if ($this->resolvedBlindIndexKey !== null) {
            return $this->resolvedBlindIndexKey;
        }

        $key = $this->blindIndexKey ?? (string) config('security.field_encryption.blind_index_key', '');
        if ($key === '') {
            $key = hash_hkdf(
                'sha256',
                $this->readKeyFile($this->getPrivateKeyPath(), '私钥'),
                32,
                'field-blind-index-v1'
            );
        }

        return $this->resolvedBlindIndexKey = $key;
    }

    private function decodePayloadPart(array $payload, string $key): string
    {
        if (!isset($payload[$key]) || !is_string($payload[$key])) {
            throw new RuntimeException('敏感数据密文载荷缺少字段');
        }

        $value = base64_decode($payload[$key], true);
        if ($value === false) {
            throw new RuntimeException('敏感数据密文载荷字段无效');
        }

        return $value;
    }
}

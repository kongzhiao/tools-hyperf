<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private SensitiveDataCipher $cipher;

    public function __construct(SensitiveDataCipher $cipher)
    {
        $this->cipher = $cipher;
    }

    public function isVerificationEnabled(): bool
    {
        return (bool) config('security.totp.verification_enabled', true);
    }

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    public function encryptSecret(string $secret): string
    {
        return (string) $this->cipher->encrypt($secret);
    }

    public function decryptSecret(string $encryptedSecret): string
    {
        return (string) $this->cipher->decrypt($encryptedSecret);
    }

    public function verifyEncryptedSecret(string $encryptedSecret, string $code): bool
    {
        if (!$this->isVerificationEnabled()) {
            return true;
        }

        return $this->verify($this->decryptSecret($encryptedSecret), $code);
    }

    public function verify(string $secret, string $code, int $window = 1): bool
    {
        return $this->verifyAt($secret, $code, time(), $window);
    }

    public function verifyAt(string $secret, string $code, int $timestamp, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $counter = (int) floor($timestamp / 30);
        for ($offset = -$window; $offset <= $window; ++$offset) {
            if (hash_equals($this->codeAt($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $username, string $secret): string
    {
        $issuer = (string) config('app_name', '共享救助信息服务平台');
        $label = rawurlencode($issuer . ':' . $username);
        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            $label,
            rawurlencode($secret),
            rawurlencode($issuer)
        );
    }

    private function codeAt(string $secret, int $counter): string
    {
        if ($counter < 0) {
            throw new RuntimeException('TOTP计数器无效');
        }

        $key = $this->base32Decode($secret);
        $high = (int) floor($counter / 4294967296);
        $low = $counter % 4294967296;
        $hash = hash_hmac('sha1', pack('N2', $high, $low), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $value) ?? '');
        if ($value === '') {
            throw new RuntimeException('TOTP密钥无效');
        }

        $bits = '';
        foreach (str_split($value) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('TOTP密钥无效');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}

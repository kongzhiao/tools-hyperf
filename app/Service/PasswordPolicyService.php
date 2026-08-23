<?php

declare(strict_types=1);

namespace App\Service;

class PasswordPolicyService
{
    public function validate(string $password): ?string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < 8) {
            return '密码至少8位';
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            return '密码必须包含字母';
        }
        if (!preg_match('/[^A-Za-z0-9\s]/u', $password)) {
            return '密码必须包含特殊符号';
        }

        return null;
    }
}

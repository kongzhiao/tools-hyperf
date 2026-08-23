<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\OperationLog;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;

class OperationLogService
{
    public function record(
        string $module,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?string $description = null,
        array $params = [],
        string $status = 'success',
        ?string $errorMessage = null,
        array $actor = []
    ): void {
        try {
            $request = $this->getRequest();
            $user = $request ? $request->getAttribute('user') : null;
            $requestUserId = $request ? $request->getAttribute('userId') : null;
            $requestUsername = is_array($user) ? ($user['username'] ?? null) : null;

            OperationLog::create([
                'user_id' => $actor['user_id'] ?? $requestUserId,
                'username' => $actor['username'] ?? $requestUsername,
                'module' => $module,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'description' => $description,
                'params' => $this->sanitizeParams($params),
                'ip' => $request ? $request->getHeaderLine('x-real-ip') ?: $request->getHeaderLine('x-forwarded-for') : null,
                'user_agent' => $request ? mb_substr($request->getHeaderLine('user-agent'), 0, 255) : null,
                'status' => $status,
                'error_message' => $errorMessage,
            ]);
        } catch (\Throwable $e) {
            error_log('Operation log failed: ' . $e->getMessage());
        }
    }

    private function getRequest(): ?RequestInterface
    {
        try {
            $container = ApplicationContext::getContainer();
            return $container->has(RequestInterface::class) ? $container->get(RequestInterface::class) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function sanitizeParams(array $params): array
    {
        foreach ($params as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (is_array($value)) {
                $params[$key] = $this->sanitizeParams($value);
                continue;
            }

            if ($this->isPasswordInputKey($normalizedKey)) {
                $params[$key] = is_string($value) ? $this->maskPassword($value) : '***';
                continue;
            }

            if ($this->isFullyHiddenKey($normalizedKey)) {
                $params[$key] = '***';
            }
        }

        return $params;
    }

    private function isPasswordInputKey(string $key): bool
    {
        if (strpos($key, 'hash') !== false) {
            return false;
        }

        return $key === 'password'
            || substr($key, -9) === '_password'
            || strpos($key, 'password_') === 0;
    }

    private function isFullyHiddenKey(string $key): bool
    {
        if (strpos($key, 'password_hash') !== false || strpos($key, 'totp_secret') !== false) {
            return true;
        }

        return in_array($key, [
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'secret',
        ], true);
    }

    private function maskPassword(string $password): string
    {
        $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
        if ($length < 5) {
            return '***';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($password, 0, 2, 'UTF-8')
                . '***'
                . mb_substr($password, -2, 2, 'UTF-8');
        }

        return substr($password, 0, 2) . '***' . substr($password, -2);
    }
}

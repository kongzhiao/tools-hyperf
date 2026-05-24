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
        ?string $errorMessage = null
    ): void {
        try {
            $request = $this->getRequest();
            $user = $request ? $request->getAttribute('user') : null;

            OperationLog::create([
                'user_id' => $request ? $request->getAttribute('userId') : null,
                'username' => is_array($user) ? ($user['username'] ?? null) : null,
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
        $sensitiveKeys = ['password', 'token', 'authorization'];

        foreach ($params as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $params[$key] = '***';
            }
        }

        return $params;
    }
}

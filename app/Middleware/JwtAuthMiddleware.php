<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Model\User;
use App\Service\AuthSessionService;
use App\Service\TotpService;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Redis\Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthMiddleware implements MiddlewareInterface
{
    private HttpResponse $response;

    private AuthSessionService $sessionService;

    private TotpService $totpService;

    public function __construct(
        HttpResponse $response,
        AuthSessionService $sessionService,
        TotpService $totpService
    ) {
        $this->response = $response;
        $this->sessionService = $sessionService;
        $this->totpService = $totpService;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = str_replace('Bearer ', '', $request->getHeaderLine('Authorization'));
        if ($token === '') {
            $queryParams = $request->getQueryParams();
            $token = (string) ($queryParams['token'] ?? '');
        }

        if ($token === '') {
            return $this->unauthorized('Token不能为空');
        }

        try {
            $payload = $this->sessionService->decode($token);
        } catch (\Throwable $e) {
            return $this->unauthorized('Token无效');
        }

        $userId = (int) ($payload->user_id ?? 0);
        $sid = (string) ($payload->sid ?? '');
        $tokenVersion = (int) ($payload->sv ?? 0);
        if ($userId <= 0 || $sid === '' || $tokenVersion <= 0 || $this->sessionService->isRevoked($sid)) {
            return $this->unauthorized('Token已失效');
        }

        try {
            $session = $this->sessionService->get($sid);
        } catch (\Throwable $e) {
            return $this->response->json(['code' => 503, 'msg' => '认证服务暂不可用，请稍后重试']);
        }

        if (!$session) {
            return $this->expiredSessionResponse($payload);
        }

        if ((int) ($session['user_id'] ?? 0) !== $userId
            || (int) ($session['session_version'] ?? 0) !== $tokenVersion) {
            return $this->unauthorized('会话已失效，请重新登录');
        }

        $userData = $this->getUserData($userId);
        if (!$userData) {
            return $this->unauthorized('用户不存在或已被禁用');
        }
        if ((int) ($userData['session_version'] ?? 0) !== $tokenVersion) {
            return $this->unauthorized('账号安全信息已变化，请重新登录');
        }

        try {
            $this->sessionService->touch($session);
        } catch (\Throwable $e) {
            return $this->response->json(['code' => 503, 'msg' => '会话续期暂不可用，请稍后重试']);
        }

        $request = $request
            ->withAttribute('userId', $userId)
            ->withAttribute('user', $userData)
            ->withAttribute('username', $userData['username'] ?? '')
            ->withAttribute('sessionId', $sid);

        return $handler->handle($request);
    }

    private function getUserData(int $userId): ?array
    {
        $redis = ApplicationContext::getContainer()->get(Redis::class);
        $cacheKey = 'user:cache:' . $userId;
        try {
            $cached = $redis->get($cacheKey);
            if ($cached) {
                $data = unserialize((string) $cached, ['allowed_classes' => false]);
                if (is_array($data) && isset($data['session_version'])) {
                    return $data;
                }
            }
        } catch (\Throwable $e) {
            // 缓存读取失败时继续读取数据库。
        }

        $user = User::query()->with(['roles.permissions', 'town'])->find($userId);
        if (!$user) {
            return null;
        }

        $data = $user->toJwtArray();
        try {
            $redis->setex($cacheKey, 7200, serialize($data));
        } catch (\Throwable $e) {
            // 用户缓存失败不影响已由Redis会话确认的本次请求。
        }

        return $data;
    }

    private function expiredSessionResponse(object $payload): ResponseInterface
    {
        if (!$this->sessionService->canReauthenticate($payload)) {
            return $this->unauthorized('会话已过期，请重新登录');
        }

        $user = User::find((int) ($payload->user_id ?? 0));
        if (!$user || (int) ($payload->sv ?? 0) !== max(1, (int) ($user->session_version ?? 1))) {
            return $this->unauthorized('会话已过期，请重新登录');
        }

        if (!$user->isTotpBound()) {
            $action = 'login';
            $message = '会话已过期，请重新登录';
        } elseif (!$this->totpService->isVerificationEnabled()) {
            $action = 'reauth';
            $message = '会话已过期，请确认续期';
        } else {
            $action = 'reauth_totp';
            $message = '会话已过期，请输入动态验证码继续使用';
        }

        return $this->response->json([
            'code' => 401,
            'msg' => $message,
            'data' => [
                'auth_action' => $action,
                'reauth_required' => $action !== 'login',
            ],
        ]);
    }

    private function unauthorized(string $message): ResponseInterface
    {
        return $this->response->json(['code' => 401, 'msg' => $message]);
    }
}

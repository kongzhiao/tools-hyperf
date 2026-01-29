<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Model\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Redis\Redis;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Context\ApplicationContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class JwtAuthMiddleware implements MiddlewareInterface
{
    /**
     * @var HttpResponse
     */
    protected $response;

    /**
     * @Inject
     * @var Redis
     */
    protected $redis;

    public function __construct(HttpResponse $response)
    {
        $this->response = $response;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 优先从 Header 获取 token，其次从 query 参数获取（方便本地测试）
        $token = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $token);
        
        if (!$token) {
            $queryParams = $request->getQueryParams();
            $token = $queryParams['token'] ?? '';
        }

        if (!$token) {
            return $this->response->json([
                'code' => 401,
                'msg' => 'Token 不能为空'
            ]);
        }

        try {
            $jwtSecret = env('JWT_SECRET', 'your-secret-key');
            $payload = JWT::decode($token, new Key($jwtSecret, 'HS256'));

            if (!isset($payload->user_id)) {
                return $this->response->json([
                    'code' => 401,
                    'msg' => 'Token payload 格式不正确'
                ]);
            }

            $userId = $payload->user_id;
            $cacheKey = 'user:cache:' . $userId;
            $userData = null;

            // 1. 优先从 Redis 获取
            try {
                $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
                $cached = $redis->get($cacheKey);
                if ($cached) {
                    $userData = unserialize($cached);
                }
            } catch (\Exception $e) {
                error_log('Redis get failed in middleware: ' . $e->getMessage());
            }

            // 2. 缓存失效，从数据库读取并补全缓存
            if (!$userData) {
                $user = User::query()->find($userId);
                if (!$user) {
                    return $this->response->json([
                        'code' => 401,
                        'msg' => '用户不存在或已被禁用'
                    ]);
                }
                $userData = $user->toJwtArray();

                // 同步回 Redis (2小时)
                try {
                    $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
                    $redis->set($cacheKey, serialize($userData), 7200);
                } catch (\Exception $e) {
                    error_log('Redis set failed in middleware: ' . $e->getMessage());
                }
            }

            // 将用户信息注入请求属性
            $request = $request->withAttribute('userId', $userId)
                ->withAttribute('user', $userData) // 注意：这里注入的是数组形式（toJwtArray）
                ->withAttribute('username', $userData['username'] ?? '');

            return $handler->handle($request);

        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 401,
                'msg' => 'Token 无效或已过期: ' . $e->getMessage()
            ]);
        }
    }
}

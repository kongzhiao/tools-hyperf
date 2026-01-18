<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Redis\Redis;
use Hyperf\Context\ApplicationContext;

/**
 * @Controller(prefix="/api")
 */
class AuthController extends AbstractController
{
    /**
     * @Inject
     * @var Redis
     */
    protected $redis;


    /**
     * 用户登录
     * @PostMapping(path="/login")
     */
    public function login(RequestInterface $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');

        if (!$username || !$password) {
            return $this->response->json([
                'code' => 400,
                'msg' => '用户名和密码不能为空'
            ]);
        }

        $user = User::query()->where('username', $username)->first();

        if (!$user || !password_verify((string) $password, $user->password)) {
            return $this->response->json([
                'code' => 401,
                'msg' => '用户名或密码错误'
            ]);
        }

        // 生成 JWT Token
        $payload = [
            'user_id' => $user->id,
            'username' => $user->username,
            'iat' => time(),
            'exp' => time() + 7200, // 2小时过期
        ];

        $jwtSecret = env('JWT_SECRET', 'your-secret-key');
        $token = JWT::encode($payload, $jwtSecret, 'HS256');

        // 将用户信息存入 Redis 缓存 (2小时)
        try {
            $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
            $cacheKey = 'user:cache:' . $user->id;
            $redis->set($cacheKey, serialize($user->toJwtArray()), 7200);
        } catch (\Exception $e) {
            // Redis 失败不影响登录，但记录日志
            error_log('Redis cache failed for user ' . $user->id . ': ' . $e->getMessage());
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '登录成功',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'nickname' => $user->nickname,
                ]
            ]
        ]);
    }

    /**
     * 获取用户信息
     * @RequestMapping(path="/user/info", methods="get")
     */
    public function info(RequestInterface $request)
    {
        $user = $request->getAttribute('user');

        if (!$user) {
            return $this->response->json([
                'code' => 401,
                'msg' => '未登录或 Token 已失效'
            ]);
        }

        $data = is_array($user) ? $user : $user->toJwtArray();

        return $this->response->json([
            'code' => 0,
            'msg' => '获取成功',
            'data' => $data
        ]);
    }

    /**
     * 用户登出
     * @PostMapping(path="/logout")
     */
    public function logout(RequestInterface $request)
    {
        $userId = $request->getAttribute('userId');
        if ($userId) {
            try {
                $redis = $this->redis ?? ApplicationContext::getContainer()->get(Redis::class);
                $redis->del('user:cache:' . $userId);
            } catch (\Exception $e) {
                error_log('Redis clear failed for user ' . $userId . ': ' . $e->getMessage());
            }
        }

        return $this->response->json([
            'code' => 0,
            'msg' => '登出成功'
        ]);
    }
}
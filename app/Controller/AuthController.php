<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\PostMapping;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/api")
 */
class AuthController extends AbstractController
{


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

        if (!$user || !password_verify($password, $user->password)) {
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
        $token = $request->getHeaderLine('Authorization');
        $token = str_replace('Bearer ', '', $token);

        if (!$token) {
            return $this->response->json([
                'code' => 401,
                'msg' => 'Token 不能为空'
            ]);
        }

        try {
            $jwtSecret = env('JWT_SECRET', 'your-secret-key');
            $payload = JWT::decode($token, new Key($jwtSecret, 'HS256'));

            $user = User::query()->with('roles.permissions')->find($payload->user_id);

            if (!$user) {
                return $this->response->json([
                    'code' => 401,
                    'msg' => '用户不存在'
                ]);
            }

            return $this->response->json([
                'code' => 0,
                'msg' => '获取成功',
                'data' => $user->toJwtArray()
            ]);

        } catch (\Exception $e) {
            return $this->response->json([
                'code' => 401,
                'msg' => 'Token 无效'
            ]);
        }
    }

    /**
     * 用户登出
     * @PostMapping(path="/logout")
     */
    public function logout()
    {
        return $this->response->json([
            'code' => 0,
            'msg' => '登出成功'
        ]);
    }
}
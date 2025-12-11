<?php
declare(strict_types=1);

/**
 * @OA\Info(
 *     title="共享救助信息服务平台 API Doc",
 *     version="1.0.0",
 *     description="共享救助信息服务平台 API documentation for Hyperf 3.x"
 * )
 */
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Controller;
use Hyperf\HttpServer\Contract\RequestInterface;

/**
 * @Controller(prefix="/")
 */
class IndexController extends AbstractController
{
    /**
     * @OA\Post(
     *     path="/login",
     *     operationId="login",
     *     summary="用户登录",
     *     @OA\RequestBody(
     *         required=true,
     *         description="登录参数: 用户名与密码",
     *         @OA\JsonContent(
     *             required={"username", "password"},
     *             @OA\Property(property="username", type="string", description="用户名"),
     *             @OA\Property(property="password", type="string", description="密码")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="登录成功，返回token",
     *         @OA\JsonContent(
     *             oneOf={
     *                 @OA\Schema(
     *                     @OA\Property(property="token", type="string", example="mocked-jwt-token")
     *                 ),
     *                 @OA\Schema(
     *                     @OA\Property(property="error", type="string", example="用户名或密码错误")
     *                 )
     *             }
     *         )
     *     )
     * )
     * @RequestMapping(path="/login", methods="post")
     */
    public function login(RequestInterface $request)
    {
        $username = $request->input('username');
        $password = $request->input('password');
        // 这里应有实际认证逻辑，示例直接返回token
        if ($username === 'admin' && $password === 'admin') {
            return ['token' => 'mocked-jwt-token'];
        }
        return ['error' => '用户名或密码错误'];
    }

    /**
     * @OA\Post(
     *     path="/logout",
     *     operationId="logout",
     *     summary="用户退出登录",
     *     @OA\Response(
     *         response=200,
     *         description="退出成功",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="退出成功")
     *         )
     *     )
     * )
     * @RequestMapping(path="/logout", methods="post")
     */
    public function logout()
    {
        // 这里应有实际登出逻辑，示例直接返回
        return ['message' => '退出成功'];
    }
    /**
     * @OA\Get(
     *     path="/",
     *     operationId="index",
     *     summary="首页",
     *     @OA\Response(
     *         response=200,
     *         description="成功"
     *     )
     * )
     */
    public function index()
    {
        $user = $this->request->input('user', 'Hyperf');
        $method = $this->request->getMethod();

        return [
            'method' => $method,
            'message' => "Hello {$user}.",
        ];
    }

    /**
     * @OA\Get(
     *     path="/health",
     *     operationId="health",
     *     summary="健康检查",
     *     @OA\Response(
     *         response=200,
     *         description="服务健康状态",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="ok"),
     *             @OA\Property(property="timestamp", type="string", example="2024-01-01T00:00:00Z"),
     *             @OA\Property(property="version", type="string", example="1.0.0")
     *         )
     *     )
     * )
     * @RequestMapping(path="/health", methods="get")
     */
    public function health()
    {
        return [
            'status' => 'ok',
            'timestamp' => date('c'),
            'version' => '1.0.0',
            'service' => 'Insurance Data Import Service'
        ];
    }
}

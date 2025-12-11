<?php
namespace App\Middleware;

use App\Service\RbacService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;

class PermissionMiddleware implements MiddlewareInterface
{
    protected $rbacService;
    protected $response;

    public function __construct(ContainerInterface $container, RbacService $rbacService, HttpResponse $response)
    {
        $this->rbacService = $rbacService;
        $this->response = $response;
    }

    public function process(\Psr\Http\Message\ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $userId = $request->getAttribute('userId'); // JWT中间件应设置
        $permission = $request->getAttribute('permission'); // 路由或控制器设置
        if (!$userId || !$permission) {
            return $this->response->json(['message' => '无权限'], 403);
        }
        if (!$this->rbacService->checkPermission($userId, $permission)) {
            return $this->response->json(['message' => '无权限'], 403);
        }
        return $handler->handle($request);
    }
}

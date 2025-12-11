<?php
declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Controller;

use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Psr\Container\ContainerInterface;

abstract class AbstractController
{
    /**
     * @Inject
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @Inject
     * @var RequestInterface
     */
    protected $request;

    /**
     * @Inject
     * @var ResponseInterface
     */
    protected $response;

    public function __construct(
        ContainerInterface $container = null,
        RequestInterface $request = null,
        ResponseInterface $response = null
    ) {
        if ($container) {
            $this->container = $container;
        }
        
        if ($request) {
            $this->request = $request;
        } else if (function_exists('make')) {
            try {
                $this->request = make(RequestInterface::class);
            } catch (\Exception $e) {
                // 如果无法创建 RequestInterface，记录错误但不抛出异常
                error_log('Failed to create RequestInterface: ' . $e->getMessage());
            }
        }
        
        if ($response) {
            $this->response = $response;
        } else if (function_exists('make')) {
            try {
                $this->response = make(ResponseInterface::class);
            } catch (\Exception $e) {
                // 如果无法创建 ResponseInterface，记录错误但不抛出异常
                error_log('Failed to create ResponseInterface: ' . $e->getMessage());
            }
        }
    }

    /**
     * 成功响应
     */
    protected function success($data = null, $message = 'success')
    {
        // 直接使用 ApplicationContext 获取 ResponseInterface
        try {
            $response = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\HttpServer\Contract\ResponseInterface::class);
            
            return $response->json([
                'code' => 0,
                'message' => $message,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            error_log('Failed to get ResponseInterface in success method: ' . $e->getMessage());
            
            // 尝试使用注入的response属性
            if (isset($this->response) && method_exists($this->response, 'json')) {
                return $this->response->json([
                    'code' => 0,
                    'message' => $message,
                    'data' => $data,
                ]);
            }
            
            // 如果都失败了，抛出异常而不是返回数组
            throw new \RuntimeException('无法创建响应对象: ' . $e->getMessage());
        }
    }

    /**
     * 错误响应
     */
    protected function error($message = 'error', $code = 1)
    {
        // 直接使用 ApplicationContext 获取 ResponseInterface
        try {
            $response = \Hyperf\Context\ApplicationContext::getContainer()
                ->get(\Hyperf\HttpServer\Contract\ResponseInterface::class);
            
            return $response->json([
                'code' => $code,
                'message' => $message,
                'data' => null,
            ]);
        } catch (\Exception $e) {
            error_log('Failed to get ResponseInterface in error method: ' . $e->getMessage());
            
            // 尝试使用注入的response属性
            if (isset($this->response) && method_exists($this->response, 'json')) {
                return $this->response->json([
                    'code' => $code,
                    'message' => $message,
                    'data' => null,
                ]);
            }
            
            // 如果都失败了，抛出异常而不是返回数组
            throw new \RuntimeException('无法创建响应对象: ' . $e->getMessage());
        }
    }
}

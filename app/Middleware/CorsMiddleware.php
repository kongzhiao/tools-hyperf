<?php

namespace App\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;

class CorsMiddleware implements MiddlewareInterface
{
    protected $response;

    public function __construct(ContainerInterface $container, HttpResponse $response)
    {
        $this->response = $response;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        error_log('[CORS] ' . $request->getMethod() . ' ' . $request->getUri()->getPath());
        
        // 处理预检请求 (OPTIONS)
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            error_log('[CORS] Preflight OPTIONS handled.');
            
            $origin = $request->getHeaderLine('Origin') ?: '*';
            
            // 设置 CORS 头
            $headers = [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => 'DNT,Keep-Alive,User-Agent,X-Requested-With,If-Modified-Since,Cache-Control,Content-Type,Authorization,X-Token',
                'Access-Control-Max-Age' => '86400', // 24小时
            ];
            
            $response = $this->response->json(['code' => 204, 'message' => 'No Content'], 204);
            foreach ($headers as $key => $val) {
                $response = $response->withHeader($key, $val);
            }
            
            return $response;
        }
        
        // 处理实际请求
        $response = $handler->handle($request);
        
        // 确保响应是有效的ResponseInterface对象
        if (!$response instanceof ResponseInterface) {
            error_log('[CORS] Warning: Response is not a valid ResponseInterface object: ' . gettype($response));
            
            // 尝试创建一个有效的响应对象
            try {
                $response = $this->response->json([
                    'code' => 500,
                    'message' => 'Internal Server Error: Invalid response object',
                    'data' => null
                ]);
            } catch (\Exception $e) {
                error_log('[CORS] Error creating fallback response: ' . $e->getMessage());
                // 如果连fallback都失败了，返回一个简单的响应
                $response = $this->response->json([
                    'code' => 500,
                    'message' => 'Internal Server Error: Invalid response object',
                    'data' => null
                ]);
            }
        }
        
        $origin = $request->getHeaderLine('Origin') ?: '*';
        
        // 允许的域名列表，可以根据需要调整
        $allowedOrigins = [
            'http://hyperf9510.xfox.site',
            'https://hyperf9510.xfox.site',
            'http://localhost:8000',  // 添加本地开发环境
            'http://127.0.0.1:8000',  // 添加IP地址形式
            // 添加其他允许的域名
        ];
        
        // 检查 Origin 是否在允许列表中
        $finalOrigin = in_array($origin, $allowedOrigins) ? $origin : $allowedOrigins[0];
        
        $headers = [
            'Access-Control-Allow-Origin' => $finalOrigin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Expose-Headers' => 'Authorization, Content-Disposition',
            'Vary' => 'Origin',
        ];
        
        foreach ($headers as $key => $val) {
            $response = $response->withHeader($key, $val);
        }
        
        error_log('[CORS] Response processed successfully for: ' . $request->getUri()->getPath());
        
        return $response;
    }
}
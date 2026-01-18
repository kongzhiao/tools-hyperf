<?php

declare(strict_types=1);

namespace App\Controller;

use App\Job\DemoJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;
use Hyperf\HttpServer\Annotation\Controller;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;

class QueueDemoController extends AbstractController
{
    protected $driver;

    public function __construct(DriverFactory $driverFactory)
    {
        $this->driver = $driverFactory->get('default');
    }

    /**
     * 推送任务到队列
     * URL: /queue/push
     */
    public function push(RequestInterface $request, ResponseInterface $response)
    {
        $params = $request->all();
        $params['time'] = date('Y-m-d H:i:s');
        // 允许通过参数 fail=1 来模拟失败
        $params['should_fail'] = (bool) $request->input('fail', 0);

        // 投递任务
        $this->driver->push(new DemoJob($params));

        return $response->json([
            'code' => 200,
            'message' => '任务已加入队列',
            'data' => $params
        ]);
    }
}

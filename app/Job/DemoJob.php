<?php

declare(strict_types=1);

namespace App\Job;

use Hyperf\AsyncQueue\Job;
use Hyperf\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class DemoJob extends AbstractJob
{
    public $params;

    /**
     * 设置最大重试次数
     */
    protected int $maxAttempts = 1;

    public function __construct($params)
    {
        // 这里项目传入的参数
        $this->params = $params;
    }

    public function handle()
    {
        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $stdout = $container->get(\Hyperf\Contract\StdoutLoggerInterface::class);

        // --- 执行前 ---
        $msg = sprintf("[%s] [Before] 准备处理任务, 参数: %s", date('Y-m-d H:i:s'), json_encode($this->params));
        $logger->info($msg);
        $stdout->info($msg); // 强制输出到控制台

        // 演示：失败触发
        if (!empty($this->params['should_fail'])) {
            $stdout->warning("即将模拟抛出异常...");
            throw new \Exception('这是演示任务执行失败的异常信息');
        }

        // 成功后的逻辑...
        $stdout->info("任务核心逻辑成功完成！");
    }

    /**
     * 当任务达到最大重试次数依然失败后，会调用此方法
     */
    public function failed(\Throwable $exception)
    {
        // 最原始的验证：如果执行到这里，runtime 下一定会产生这个文件
        file_put_contents(BASE_PATH . '/runtime/failed_check.txt', date('Y-m-d H:i:s') . " - failed hook called\n", FILE_APPEND);

        $container = \Hyperf\Context\ApplicationContext::getContainer();
        $logger = $container->get(LoggerFactory::class)->get('default');
        $stdout = $container->get(\Hyperf\Contract\StdoutLoggerInterface::class);

        $msg = sprintf("[%s] [Failed] 任务经过重试后最终彻底失败！错误: %s", date('Y-m-d H:i:s'), $exception->getMessage());

        // 重点：使用 StdoutLogger 确保在 server:watch 终端可见
        $stdout->error($msg);

        // 记录到文件日志
        $logger->error($msg);
    }
}

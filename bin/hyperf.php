#!/usr/bin/env php
<?php

ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '2G'); // 增加内存限制
ini_set('upload_max_filesize', '100M');
ini_set('post_max_size', '100M');
ini_set('max_file_uploads', '20');
ini_set('max_execution_time', '18000'); // 增加到30分钟
ini_set('max_input_time', '18000'); // 增加到30分钟
ini_set('set_time_limit', '18000'); // 增加到30分钟

// 设置更宽松的超时配置
ini_set('default_socket_timeout', '300'); // 5分钟socket超时
ini_set('mysql.connect_timeout', '300'); // MySQL连接超时
ini_set('mysql.read_timeout', '300'); // MySQL读取超时

error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

require BASE_PATH . '/vendor/autoload.php';

// Self-called anonymous function that creates its own scope and keep the global namespace clean.
(function () {
    Hyperf\Di\ClassLoader::init();
    /** @var Psr\Container\ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    $application = $container->get(Hyperf\Contract\ApplicationInterface::class);
    $application->run();
})();

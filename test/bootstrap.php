<?php
declare(strict_types=1);

!defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
require_once BASE_PATH . '/vendor/hyperf/support/src/Functions.php';
if (!function_exists('env')) {
    function env($key, $default = null) {
        return \Hyperf\Support\env($key, $default);
    }
}
if (!function_exists('make')) {
    function make(string $name, array $parameters = []) {
        return \Hyperf\Support\make($name, $parameters);
    }
}
require BASE_PATH . '/vendor/autoload.php';
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');

error_reporting(E_ALL);
date_default_timezone_set('Asia/Shanghai');


if (extension_loaded('swoole') && defined('SWOOLE_HOOK_ALL')) {
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
}

Hyperf\Di\ClassLoader::init();

$container = require BASE_PATH . '/config/container.php';

$container->get(Hyperf\Contract\ApplicationInterface::class);

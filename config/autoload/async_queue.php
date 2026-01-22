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
return [
    'default' => [
        'driver' => Hyperf\AsyncQueue\Driver\RedisDriver::class,
        'redis' => [
            'pool' => 'default',
        ],
        'channel' => '{queue}',
        'timeout' => 5,
        'retry_seconds' => [5, 10, 30,120,300],
        'handle_timeout' => 86400,
        'processes' => 1,
        'concurrent' => [
            'limit' => 1,
        ],
        'max_messages' => 1000,
    ],
];

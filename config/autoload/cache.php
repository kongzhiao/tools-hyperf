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
        // 注释掉Redis缓存，改为文件缓存或完全禁用
        // 'driver' => Hyperf\Cache\Driver\RedisDriver::class,
        // 'packer' => Hyperf\Utils\Packer\PhpSerializerPacker::class,
        // 'prefix' => 'c:',
    ],
];

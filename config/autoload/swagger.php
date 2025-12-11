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
    // 是否启用 swagger
    'enable' => false,
    // swagger.json 生成目录
    'json_dir' => BASE_PATH . '/storage/swagger',
    // swagger-ui 静态页面目录（如有集成）
    'html' => BASE_PATH . '/storage/swagger',
    // swagger.json 访问路径
    'url' => '/swagger',
    // 是否自动生成 swagger.json
    'auto_generate' => true,
    // 扫描的控制器/目录，建议明确指定
    'scan' => [
        'paths' => [
            BASE_PATH . '/app/Controller',
        ],
    ],
    // 可自定义处理器
    'processors' => [
        // users can append their own processors here
    ],
];

<?php

declare(strict_types=1);

/**
 * OCR服务配置
 * 
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

return [
    // OCR服务基础配置
    'service' => [
        // OCR服务URL
        'url' => env('OCR_SERVICE_URL', 'http://127.0.0.1:8001/ocr/recognize'),
        
        // 请求超时时间（秒）
        'timeout' => env('OCR_SERVICE_TIMEOUT', 60),
        
        // 连接超时时间（秒）
        'connect_timeout' => env('OCR_SERVICE_CONNECT_TIMEOUT', 10),
        
        // 重试次数
        'retry_times' => env('OCR_SERVICE_RETRY_TIMES', 3),
        
        // 重试间隔（秒）
        'retry_interval' => env('OCR_SERVICE_RETRY_INTERVAL', 2),
    ],
    
    // HTTP请求配置
    'http' => [
        // User-Agent
        'user_agent' => env('OCR_SERVICE_USER_AGENT', 'Hyperf-OCR-Client/1.0'),
        
        // 是否验证SSL证书
        'verify_ssl' => env('OCR_SERVICE_VERIFY_SSL', false),
        
        // 是否验证SSL主机名
        'verify_host' => env('OCR_SERVICE_VERIFY_HOST', false),
    ],
    
    // 文件上传配置
    'upload' => [
        // 最大文件大小（字节）
        'max_file_size' => env('OCR_MAX_FILE_SIZE', 10 * 1024 * 1024), // 10MB
        
        // 允许的文件类型
        'allowed_types' => [
            'image/jpeg',
            'image/jpg', 
            'image/png',
            'image/gif'
        ],
        
        // 临时文件目录
        'temp_dir' => env('OCR_TEMP_DIR', sys_get_temp_dir()),
    ],
    
    // 日志配置
    'logging' => [
        // 是否启用详细日志
        'enabled' => env('OCR_LOGGING_ENABLED', true),
        
        // 日志级别
        'level' => env('OCR_LOGGING_LEVEL', 'info'),
    ],
];

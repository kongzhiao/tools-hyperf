<?php

declare(strict_types=1);

return [
    'field_encryption' => [
        'public_key_path' => env('FIELD_PUBLIC_KEY_PATH', BASE_PATH . '/config/extends/global_publickey.pem'),
        'private_key_path' => env('FIELD_PRIVATE_KEY_PATH', BASE_PATH . '/config/extends/global_privatekey.key'),
        'blind_index_key' => env('FIELD_INDEX_KEY', ''),
    ],
    'auth' => [
        'session_ttl' => 86400,
        'session_touch_interval' => 600,
        'reauth_token_ttl' => 2592000,
        'challenge_ttl' => 600,
    ],
    'totp' => [
        // 默认开启；仅本地开发或自动化测试按需显式关闭。
        'verification_enabled' => filter_var(
            env('TOTP_VERIFICATION_ENABLED', true),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],
];

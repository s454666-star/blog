<?php

return [
    '85sugarbaby' => [
        'enabled' => env('CRAWLER_85SUGARBABY_ENABLED', true),
        'local_login_url' => env(
            'CRAWLER_85SUGARBABY_LOCAL_LOGIN_URL',
            'https://blog.test/crawler/85sugarbaby/session/login'
        ),
        'import_output_dir' => env(
            'CRAWLER_85SUGARBABY_IMPORT_OUTPUT_DIR',
            storage_path('app/google-login-crawler/85sugarbaby-import')
        ),
        'login_output_dir' => env(
            'CRAWLER_85SUGARBABY_LOGIN_OUTPUT_DIR',
            storage_path('app/google-login-crawler/85sugarbaby-login')
        ),
        'cookie_state_path' => env(
            'CRAWLER_85SUGARBABY_COOKIE_STATE_PATH',
            storage_path('app/google-login-crawler/85sugarbaby-session-cookies.json')
        ),
        'login_lock_ttl' => (int) env('CRAWLER_85SUGARBABY_LOGIN_LOCK_TTL', 1800),
    ],
];

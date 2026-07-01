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
    ],
];

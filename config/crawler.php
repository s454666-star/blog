<?php

return [
    '85sugarbaby' => [
        'enabled' => env('CRAWLER_85SUGARBABY_ENABLED', true),
        'local_login_url' => env(
            'CRAWLER_85SUGARBABY_LOCAL_LOGIN_URL',
            'https://blog.test/crawler/85sugarbaby/session/login'
        ),
    ],
];

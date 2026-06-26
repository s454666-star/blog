<?php

return [
    'portfolio_enabled' => env('YUANTA_PORTFOLIO_ENABLED', false),
    'dashboard_token' => env('YUANTA_PORTFOLIO_DASHBOARD_TOKEN', env('ESUN_PORTFOLIO_DASHBOARD_TOKEN', '')),

    'python_bin' => env('YUANTA_PYTHON_BIN', 'python'),
    'query_script' => base_path('scripts/yuanta_portfolio_query.py'),
    'dotnet_root' => env('YUANTA_DOTNET_ROOT', ''),
    'sdk_path' => env('YUANTA_SDK_PATH', ''),

    'environment' => env('YUANTA_API_ENVIRONMENT', 'PROD'),
    'account' => env('YUANTA_ACCOUNT', ''),
    'password' => env('YUANTA_PASSWORD', ''),
    'pfx_path' => env('YUANTA_PFX_PATH', ''),
    'pfx_password' => env('YUANTA_PFX_PASSWORD', ''),

    'cache_seconds_open' => (int) env('YUANTA_PORTFOLIO_CACHE_SECONDS_OPEN', 5),
    'cache_seconds_closed' => (int) env('YUANTA_PORTFOLIO_CACHE_SECONDS_CLOSED', 600),
    'poll_seconds_open' => (int) env('YUANTA_PORTFOLIO_POLL_SECONDS_OPEN', 1),
    'minimum_query_seconds' => (int) env('YUANTA_PORTFOLIO_MINIMUM_QUERY_SECONDS', 60),
    'year_transaction_cache_days' => (int) env('YUANTA_PORTFOLIO_YEAR_TRANSACTION_CACHE_DAYS', 10),

    'timezone' => env('YUANTA_PORTFOLIO_TIMEZONE', env('ESUN_PORTFOLIO_TIMEZONE', 'Asia/Taipei')),
    'market_open_start' => env('YUANTA_PORTFOLIO_OPEN_START', env('ESUN_PORTFOLIO_OPEN_START', '09:00')),
    'market_open_end' => env('YUANTA_PORTFOLIO_OPEN_END', env('ESUN_PORTFOLIO_OPEN_END', '13:35')),
];

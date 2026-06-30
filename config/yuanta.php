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
    'futures_kline_enabled' => env('YUANTA_FUTURES_KLINE_ENABLED', env('YUANTA_PORTFOLIO_ENABLED', false)),
    'futures_kline_script' => base_path('scripts/yuanta_futures_kline_query.py'),
    'futures_kline_symbol' => env('YUANTA_FUTURES_KLINE_SYMBOL', 'TXFPM1'),
    'futures_kline_cache_seconds' => (int) env('YUANTA_FUTURES_KLINE_CACHE_SECONDS', 600),
    'futures_kline_timeout_seconds' => (int) env('YUANTA_FUTURES_KLINE_TIMEOUT_SECONDS', 70),
    'futures_kline_excluded_start' => env('YUANTA_FUTURES_KLINE_EXCLUDED_START', '09:00'),
    'futures_kline_excluded_end' => env('YUANTA_FUTURES_KLINE_EXCLUDED_END', '13:30'),

    'cache_seconds_open' => (int) env('YUANTA_PORTFOLIO_CACHE_SECONDS_OPEN', 5),
    'cache_seconds_closed' => (int) env('YUANTA_PORTFOLIO_CACHE_SECONDS_CLOSED', 600),
    'poll_seconds_open' => (int) env('YUANTA_PORTFOLIO_POLL_SECONDS_OPEN', 1),
    'minimum_query_seconds' => (int) env('YUANTA_PORTFOLIO_MINIMUM_QUERY_SECONDS', 60),
    'year_transaction_cache_days' => (int) env('YUANTA_PORTFOLIO_YEAR_TRANSACTION_CACHE_DAYS', 10),
    'margin_limit_amount' => (float) env('YUANTA_MARGIN_LIMIT_AMOUNT', 1000000),

    'timezone' => env('YUANTA_PORTFOLIO_TIMEZONE', env('ESUN_PORTFOLIO_TIMEZONE', 'Asia/Taipei')),
    'market_open_start' => env('YUANTA_PORTFOLIO_OPEN_START', env('ESUN_PORTFOLIO_OPEN_START', '09:00')),
    'market_open_end' => env('YUANTA_PORTFOLIO_OPEN_END', env('ESUN_PORTFOLIO_OPEN_END', '13:35')),
];

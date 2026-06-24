<?php

return [
    'portfolio_enabled' => env('ESUN_PORTFOLIO_ENABLED', false),
    'dashboard_token' => env('ESUN_PORTFOLIO_DASHBOARD_TOKEN', ''),

    'python_bin' => env('ESUN_PYTHON_BIN', 'python'),
    'query_script' => base_path('scripts/esun_portfolio_query.py'),

    'entry' => env('ESUN_API_ENTRY', 'https://esuntradingapi.esunsec.com.tw/api/v1'),
    'account' => env('ESUN_ACCOUNT', ''),
    'api_key' => env('ESUN_API_KEY', ''),
    'api_secret' => env('ESUN_API_SECRET', ''),
    'cert_path' => env('ESUN_CERT_PATH', ''),
    'account_password' => env('ESUN_ACCOUNT_PASSWORD', ''),
    'cert_password' => env('ESUN_CERT_PASSWORD', ''),

    'cache_seconds_open' => (int) env('ESUN_PORTFOLIO_CACHE_SECONDS_OPEN', 5),
    'cache_seconds_closed' => (int) env('ESUN_PORTFOLIO_CACHE_SECONDS_CLOSED', 600),
    'poll_seconds_open' => (int) env('ESUN_PORTFOLIO_POLL_SECONDS_OPEN', 2),

    'timezone' => env('ESUN_PORTFOLIO_TIMEZONE', 'Asia/Taipei'),
    'market_open_start' => env('ESUN_PORTFOLIO_OPEN_START', '09:00'),
    'market_open_end' => env('ESUN_PORTFOLIO_OPEN_END', '13:35'),
];

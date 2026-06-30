<?php

return [
    'portfolio_enabled' => env('ESUN_PORTFOLIO_ENABLED', false),
    'dashboard_token' => env('ESUN_PORTFOLIO_DASHBOARD_TOKEN', ''),

    'python_bin' => env('ESUN_PYTHON_BIN', 'python'),
    'query_script' => base_path('scripts/esun_portfolio_query.py'),
    'transaction_script' => base_path('scripts/esun_transactions_query.py'),
    'daemon_url' => env('ESUN_PORTFOLIO_DAEMON_URL', ''),
    'daemon_timeout_seconds' => (int) env('ESUN_PORTFOLIO_DAEMON_TIMEOUT_SECONDS', 35),

    'entry' => env('ESUN_API_ENTRY', 'https://esuntradingapi.esunsec.com.tw/api/v1'),
    'account' => env('ESUN_ACCOUNT', ''),
    'api_key' => env('ESUN_API_KEY', ''),
    'api_secret' => env('ESUN_API_SECRET', ''),
    'cert_path' => env('ESUN_CERT_PATH', ''),
    'account_password' => env('ESUN_ACCOUNT_PASSWORD', ''),
    'cert_password' => env('ESUN_CERT_PASSWORD', ''),

    'cache_seconds_open' => (int) env('ESUN_PORTFOLIO_CACHE_SECONDS_OPEN', 5),
    'cache_seconds_closed' => (int) env('ESUN_PORTFOLIO_CACHE_SECONDS_CLOSED', 600),
    'poll_seconds_open' => (int) env('ESUN_PORTFOLIO_POLL_SECONDS_OPEN', 1),
    'minimum_query_seconds' => (int) env('ESUN_PORTFOLIO_MINIMUM_QUERY_SECONDS', 45),
    'year_transaction_cache_days' => (int) env('ESUN_PORTFOLIO_YEAR_TRANSACTION_CACHE_DAYS', 10),
    'quote_cache_seconds' => (int) env('ESUN_PORTFOLIO_QUOTE_CACHE_SECONDS', 1),
    'quote_timeout_seconds' => (int) env('ESUN_PORTFOLIO_QUOTE_TIMEOUT_SECONDS', 4),
    'quote_providers' => env('ESUN_PORTFOLIO_QUOTE_PROVIDERS', 'cnyes,yahoo_tw'),
    'quote_fallback_providers' => env('ESUN_PORTFOLIO_QUOTE_FALLBACK_PROVIDERS', ''),
    'quote_rotation_seconds' => (int) env('ESUN_PORTFOLIO_QUOTE_ROTATION_SECONDS', 1),
    'quote_confirmation_required' => (int) env('ESUN_PORTFOLIO_QUOTE_CONFIRMATION_REQUIRED', 2),
    'quote_confirmation_decimals' => (int) env('ESUN_PORTFOLIO_QUOTE_CONFIRMATION_DECIMALS', 2),
    'quote_confirmation_tick_tolerance' => (float) env('ESUN_PORTFOLIO_QUOTE_CONFIRMATION_TICK_TOLERANCE', 1),

    'timezone' => env('ESUN_PORTFOLIO_TIMEZONE', 'Asia/Taipei'),
    'market_open_start' => env('ESUN_PORTFOLIO_OPEN_START', '09:00'),
    'market_open_end' => env('ESUN_PORTFOLIO_OPEN_END', '13:35'),
];

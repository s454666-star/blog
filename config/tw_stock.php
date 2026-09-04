<?php

return [
    // Confirmed share conversions; prices before effective_date are unadjusted.
    // TPEx 2026-08-28 announcement: 6696 resumes on 2026-08-31, par NT$10 -> NT$1.
    // https://m.moneydj.com/f1a.aspx?a=5bec5886-b656-44a4-95db-9d6a6bb0e249&c=MB06
    'share_conversions' => [
        '6696' => [
            ['effective_date' => '2026-08-31', 'shares_per_old_share' => 10],
        ],
        // TPEx conversion record date 2026-09-04; trading resumes 2026-09-07.
        // Broker inventory switched to the new NT$0.50 basis on the record date.
        '6949' => [
            ['effective_date' => '2026-09-04', 'shares_per_old_share' => 20],
        ],
    ],
    'annual_financial_comparisons_schedule_enabled' => env('TW_STOCK_ANNUAL_COMPARISONS_SCHEDULE_ENABLED'),
    'taiex_futures_tradingview_auth_token' => env('TRADINGVIEW_AUTH_TOKEN'),
    // Bound each request's in-memory chart history while retaining every row in MySQL.
    'taiex_futures_kline_history_limits' => [
        '5' => 12000,
        '15' => 12000,
        '60' => 3000,
    ],
    'taiex_futures_four_hour_ma5_notify_times' => [
        '08:45',
        '12:45',
        '13:45',
        '15:00',
        '19:00',
        '23:00',
    ],
    'taiex_futures_four_hour_ma5_opening_notify_times' => [
        '08:45',
        '15:00',
    ],
    'eps_growth_ranking' => [
        'base_year' => 2025,
        'forecast_years' => [2026, 2027, 2028],
        'lookback_days' => 400,
        'minimum_eligible' => 50,
        'neutral_estimate_stock_codes' => ['2455', '3081', '4971', '7711'],
        'manual_neutral_forecasts' => [
            '4971' => [
                'stock_name' => 'IET-KY',
                'forecast_date' => '2026-08-31',
                'eps_2026' => 6.10,
                'eps_2027' => 10.94,
                'analyst_count' => 1,
                'source_label' => '法人預估',
                'source_url' => 'https://www.mirrormedia.mg/external/amp/setn_1898892',
            ],
            '7711' => [
                'stock_name' => '永擎',
                'forecast_date' => '2026-04-23',
                'eps_2025' => 13.04,
                'eps_2026' => 31.31,
                'eps_2027' => 42.11,
                'analyst_count' => 1,
                'source_label' => '元大預估',
                'source_url' => 'https://www.scribd.com/document/1030346362/%E5%85%83%E5%A4%A7-7711-%E6%B0%B8%E6%93%8E-20260423',
            ],
        ],
        'neutral_2028_growth_retention' => 0.5,
        'neutral_2028_growth_min' => 0.0,
        'neutral_2028_growth_max' => 0.3,
        'cnyes_url' => 'https://api.cnyes.com/media/api/v1/newslist/category/tw_forecast',
        'finmind_url' => 'https://api.finmindtrade.com/api/v4/data',
    ],
];

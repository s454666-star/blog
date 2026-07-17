<?php

$taiexFuturesNotifyTimes = [
    '08:45',
    '12:45',
    '13:45',
    '15:00',
    '19:00',
    '23:00',
];

return [
    'annual_financial_comparisons_schedule_enabled' => env('TW_STOCK_ANNUAL_COMPARISONS_SCHEDULE_ENABLED'),
    'taiex_futures_notify_times' => $taiexFuturesNotifyTimes,
    'taiex_futures_four_hour_ma5_notify_times' => $taiexFuturesNotifyTimes,
    'taiex_futures_four_hour_ma5_opening_notify_times' => [
        '08:45',
        '15:00',
    ],
];

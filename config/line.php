<?php

return [
    'channel_id' => env('LINE_CHANNEL_ID', ''),
    'channel_secret' => env('LINE_CHANNEL_SECRET', ''),
    'channel_access_token' => env('LINE_CHANNEL_ACCESS_TOKEN', ''),
    'dashboard_notify_target_id' => env('LINE_DASHBOARD_NOTIFY_TARGET_ID', ''),
    'taiex_futures_notify_target_id' => env('LINE_TAIEX_FUTURES_NOTIFY_TARGET_ID', ''),
    'taiex_futures_notify_enabled' => env('LINE_TAIEX_FUTURES_NOTIFY_ENABLED', true),

    'yuanta_channel_id' => env('YUANTA_LINE_CHANNEL_ID', ''),
    'yuanta_channel_secret' => env('YUANTA_LINE_CHANNEL_SECRET', ''),
    'yuanta_channel_access_token' => env('YUANTA_LINE_CHANNEL_ACCESS_TOKEN', ''),
    'yuanta_dashboard_notify_target_id' => env('YUANTA_LINE_DASHBOARD_NOTIFY_TARGET_ID', ''),

    'dashboard_token_rotation_schedule_enabled' => env('LINE_DASHBOARD_TOKEN_ROTATION_SCHEDULE_ENABLED', false),
];

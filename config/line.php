<?php

return [
    'channel_secret' => env('LINE_CHANNEL_SECRET', ''),
    'dashboard_notify_target_id' => env('LINE_DASHBOARD_NOTIFY_TARGET_ID', ''),

    'yuanta_channel_id' => env('YUANTA_LINE_CHANNEL_ID', ''),
    'yuanta_channel_secret' => env('YUANTA_LINE_CHANNEL_SECRET', ''),
    'yuanta_channel_access_token' => env('YUANTA_LINE_CHANNEL_ACCESS_TOKEN', ''),
    'yuanta_dashboard_notify_target_id' => env('YUANTA_LINE_DASHBOARD_NOTIFY_TARGET_ID', ''),

    'dashboard_token_rotation_schedule_enabled' => env('LINE_DASHBOARD_TOKEN_ROTATION_SCHEDULE_ENABLED', false),
];

<?php

    return [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'filestore_bot_token' => env('TELEGRAM_FILESTORE_BOT_TOKEN'),
        'filestore_bot_username' => env('TELEGRAM_FILESTORE_BOT_USERNAME', 'filestoebot'),
        'filestore_sync_chat_id' => (int) env('TELEGRAM_FILESTORE_SYNC_CHAT_ID', 7702694790),
        'filestore_sync_bot_username' => env('TELEGRAM_FILESTORE_SYNC_BOT_USERNAME', 'filestoebot'),
        'filestore_sending_stale_seconds' => (int) env('TELEGRAM_FILESTORE_SENDING_STALE_SECONDS', 1800),
        'backup_restore_bot_token' => env('TELEGRAM_BACKUP_RESTORE_BOT_TOKEN'),
        'backup_restore_bot_username' => env('TELEGRAM_BACKUP_RESTORE_BOT_USERNAME', 'new_files_star_bot'),
        'backup_restore_target_chat_id' => (int) env('TELEGRAM_BACKUP_RESTORE_TARGET_CHAT_ID', 0),
        'backup_restore_webhook_url' => env('TELEGRAM_BACKUP_RESTORE_WEBHOOK_URL', 'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star'),
        'line_mirror' => [
            'enabled' => (bool) env('TELEGRAM_LINE_MIRROR_ENABLED', false),
            'routes' => [
                'esun' => [
                    'bot_token' => env('TELEGRAM_ESUN_NOTIFY_BOT_TOKEN'),
                    'chat_id' => env('TELEGRAM_ESUN_NOTIFY_CHAT_ID'),
                ],
                'yuanta' => [
                    'bot_token' => env('TELEGRAM_YUANTA_NOTIFY_BOT_TOKEN'),
                    'chat_id' => env('TELEGRAM_YUANTA_NOTIFY_CHAT_ID'),
                ],
                'personal' => [
                    'bot_token' => env('TELEGRAM_YUANTA_NOTIFY_BOT_TOKEN'),
                    'chat_id' => env('TELEGRAM_PERSONAL_NOTIFY_CHAT_ID'),
                ],
            ],
        ],
        'taiex_futures_notify_enabled' => env(
            'TELEGRAM_TAIEX_FUTURES_NOTIFY_ENABLED',
            env('LINE_TAIEX_FUTURES_NOTIFY_ENABLED', true),
        ),
        'resource_codes' => [
            'base_uris' => env('TELEGRAM_RESOURCE_CODE_BASE_URIS', 'http://127.0.0.1:8001,http://127.0.0.1:8002,http://127.0.0.1:8003'),
            'source_peer_ids' => env('TELEGRAM_RESOURCE_CODE_SOURCE_PEER_IDS', '3779285711,2574836051,4426897412,3742430847,2627988497,3915216305,4424746925,4314240771,2250260725,2479205833,2450312756,3717587404,3953219905'),
            'source_topic_ids' => env('TELEGRAM_RESOURCE_CODE_SOURCE_TOPIC_IDS', ''),
            'target_peer_id' => (int) env('TELEGRAM_RESOURCE_CODE_TARGET_PEER_ID', 3967395258),
            'bot_username' => env('TELEGRAM_RESOURCE_CODE_BOT_USERNAME', 'QzccxYYbot'),
            'code_type' => (int) env('TELEGRAM_RESOURCE_CODE_TYPE', 9),
            'processing_profiles' => env('TELEGRAM_RESOURCE_CODE_PROCESSING_PROFILES', '9:QzccxYYbot'),
            'scan_code_types' => env('TELEGRAM_RESOURCE_CODE_SCAN_TYPES', '9'),
            'initial_scan_limit' => (int) env('TELEGRAM_RESOURCE_CODE_INITIAL_SCAN_LIMIT', 1000),
            'scan_batch_size' => (int) env('TELEGRAM_RESOURCE_CODE_SCAN_BATCH_SIZE', 500),
            'loop_sleep_seconds' => (int) env('TELEGRAM_RESOURCE_CODE_LOOP_SLEEP_SECONDS', 10),
            'request_timeout_seconds' => (int) env('TELEGRAM_RESOURCE_CODE_REQUEST_TIMEOUT_SECONDS', 240),
        ],
    ];

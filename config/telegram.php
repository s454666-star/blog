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
        'resource_codes' => [
            'base_uris' => env('TELEGRAM_RESOURCE_CODE_BASE_URIS', 'http://127.0.0.1:8001,http://127.0.0.1:8002,http://127.0.0.1:8003'),
            'source_peer_ids' => env('TELEGRAM_RESOURCE_CODE_SOURCE_PEER_IDS', '3779285711,2352070665,2668374810,3786977217,3948153894,2895647646,2589355088,1886271900,2574836051,4436308728'),
            'source_topic_ids' => env('TELEGRAM_RESOURCE_CODE_SOURCE_TOPIC_IDS', '2589355088:11'),
            'target_peer_id' => (int) env('TELEGRAM_RESOURCE_CODE_TARGET_PEER_ID', 3967395258),
            'bot_username' => env('TELEGRAM_RESOURCE_CODE_BOT_USERNAME', 'WenJianJiJibot'),
            'code_type' => (int) env('TELEGRAM_RESOURCE_CODE_TYPE', 2),
            'processing_profiles' => env('TELEGRAM_RESOURCE_CODE_PROCESSING_PROFILES', '4:JSfilesbot'),
            'scan_code_types' => env('TELEGRAM_RESOURCE_CODE_SCAN_TYPES', '4'),
            'initial_scan_limit' => (int) env('TELEGRAM_RESOURCE_CODE_INITIAL_SCAN_LIMIT', 1000),
            'scan_batch_size' => (int) env('TELEGRAM_RESOURCE_CODE_SCAN_BATCH_SIZE', 500),
            'loop_sleep_seconds' => (int) env('TELEGRAM_RESOURCE_CODE_LOOP_SLEEP_SECONDS', 10),
            'request_timeout_seconds' => (int) env('TELEGRAM_RESOURCE_CODE_REQUEST_TIMEOUT_SECONDS', 240),
        ],
    ];

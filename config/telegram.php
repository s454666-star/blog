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
    ];

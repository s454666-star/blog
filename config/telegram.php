<?php

    return [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'filestore_bot_token' => env('TELEGRAM_FILESTORE_BOT_TOKEN'),
        'filestore_sync_chat_id' => (int) env('TELEGRAM_FILESTORE_SYNC_CHAT_ID', 7702694790),
        'filestore_sync_bot_username' => env('TELEGRAM_FILESTORE_SYNC_BOT_USERNAME', 'filestoebot'),
    ];

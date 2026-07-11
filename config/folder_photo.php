<?php

return [
    'root' => env('FOLDER_PHOTO_ROOT', '\\\\mc\\photo'),
    'stream_base_path' => env('FOLDER_PHOTO_STREAM_BASE_PATH', ''),
    'index_path' => env('FOLDER_PHOTO_INDEX_PATH', storage_path('app/folder-photo-index.json')),
    'index_refresh_seconds' => (int) env('FOLDER_PHOTO_INDEX_REFRESH_SECONDS', 3600),
    'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'],
    'random_pool_limit' => (int) env('FOLDER_PHOTO_RANDOM_POOL_LIMIT', 500),
    'initial_columns' => (int) env('FOLDER_PHOTO_INITIAL_COLUMNS', 3),
    'initial_rows' => (int) env('FOLDER_PHOTO_INITIAL_ROWS', 4),
    'max_columns' => (int) env('FOLDER_PHOTO_MAX_COLUMNS', 6),
    'max_rows' => (int) env('FOLDER_PHOTO_MAX_ROWS', 8),
    'display_min_seconds' => (int) env('FOLDER_PHOTO_DISPLAY_MIN_SECONDS', 7),
    'display_max_seconds' => (int) env('FOLDER_PHOTO_DISPLAY_MAX_SECONDS', 12),
    'app_version' => env('FOLDER_PHOTO_APP_VERSION', '2026.07.10.2'),
    'android_apk_version_code' => (int) env('FOLDER_PHOTO_ANDROID_APK_VERSION_CODE', 1),
    'android_apk_version_name' => env('FOLDER_PHOTO_ANDROID_APK_VERSION_NAME', '2026.07.10.1'),
    'android_apk_path' => env('FOLDER_PHOTO_ANDROID_APK_PATH', storage_path('app/folder-photo-app.apk')),
    'tv_android_apk_version_code' => (int) env('FOLDER_PHOTO_TV_ANDROID_APK_VERSION_CODE', 2),
    'tv_android_apk_version_name' => env('FOLDER_PHOTO_TV_ANDROID_APK_VERSION_NAME', '2026.07.11.2-tv'),
    'tv_android_apk_path' => env('FOLDER_PHOTO_TV_ANDROID_APK_PATH', storage_path('app/folder-photo-tv.apk')),
];

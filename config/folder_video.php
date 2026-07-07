<?php

return [
    'root' => env('FOLDER_VIDEO_ROOT', 'Z:\video(重跑)'),
    'good_subdirectory' => env('FOLDER_VIDEO_GOOD_SUBDIRECTORY', 'good'),
    'ffprobe_bin' => env('FOLDER_VIDEO_FFPROBE_BIN', 'C:\ffmpeg\bin\ffprobe.exe'),
    'index_filename' => env('FOLDER_VIDEO_INDEX_FILENAME', 'folder-video-index.json'),
    'extensions' => ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi'],
    'probe_on_request' => env('FOLDER_VIDEO_PROBE_ON_REQUEST', false),
    'app_version' => env('FOLDER_VIDEO_APP_VERSION', '2026.07.07.2'),
    'app_preview_max_connections' => (int) env('FOLDER_VIDEO_APP_PREVIEW_MAX_CONNECTIONS', 6),
    'app_page_limit' => (int) env('FOLDER_VIDEO_APP_PAGE_LIMIT', 36),
    'android_apk_version_code' => (int) env('FOLDER_VIDEO_ANDROID_APK_VERSION_CODE', 2),
    'android_apk_version_name' => env('FOLDER_VIDEO_ANDROID_APK_VERSION_NAME', '2026.07.07.2'),
    'android_apk_path' => env('FOLDER_VIDEO_ANDROID_APK_PATH', storage_path('app/folder-video-app.apk')),
];

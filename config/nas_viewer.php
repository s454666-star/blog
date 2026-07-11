<?php

return [
    'roots' => [
        '30t-a' => [
            'label' => '30T-A',
            'path' => env('NAS_VIEWER_ROOT_30T_A', '\\\\mc\\30T-A'),
            'stream_base_path' => '/nas-viewer-media/30t-a',
        ],
        '30t-b' => [
            'label' => '30T-B',
            'path' => env('NAS_VIEWER_ROOT_30T_B', '\\\\mc\\30T-B'),
            'stream_base_path' => '/nas-viewer-media/30t-b',
        ],
        'fhd' => [
            'label' => 'FHD',
            'path' => env('NAS_VIEWER_ROOT_FHD', '\\\\mc\\FHD'),
            'stream_base_path' => '/nas-viewer-media/fhd',
        ],
        'fhd-back' => [
            'label' => 'FHD_BACK',
            'path' => env('NAS_VIEWER_ROOT_FHD_BACK', '\\\\mc\\FHD_BACK'),
            'stream_base_path' => '/nas-viewer-media/fhd-back',
        ],
        'home' => [
            'label' => 'home',
            'path' => env('NAS_VIEWER_ROOT_HOME', '\\\\mc\\home'),
            'stream_base_path' => '/nas-viewer-media/home',
        ],
        'homes' => [
            'label' => 'homes',
            'path' => env('NAS_VIEWER_ROOT_HOMES', '\\\\mc\\homes'),
            'stream_base_path' => '/nas-viewer-media/homes',
        ],
        'photo' => [
            'label' => 'photo',
            'path' => env('NAS_VIEWER_ROOT_PHOTO', '\\\\mc\\photo'),
            'stream_base_path' => '/nas-viewer-media/photo',
        ],
        'plex-media-server' => [
            'label' => 'PlexMediaServer',
            'path' => env('NAS_VIEWER_ROOT_PLEX_MEDIA_SERVER', '\\\\mc\\PlexMediaServer'),
            'stream_base_path' => '/nas-viewer-media/plex-media-server',
        ],
        'video' => [
            'label' => 'video',
            'path' => env('NAS_VIEWER_ROOT_VIDEO', '\\\\mc\\video'),
            'stream_base_path' => '/nas-viewer-media/video',
        ],
    ],
    'video_extensions' => ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi', 'ts', 'm2ts'],
    'image_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'avif'],
    'apk_extensions' => ['apk'],
    'text_extensions' => [
        'txt', 'md', 'markdown', 'json', 'jsonl', 'csv', 'tsv', 'log', 'xml', 'yaml', 'yml',
        'ini', 'conf', 'cfg', 'php', 'js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'html', 'htm',
        'sql', 'sh', 'ps1', 'bat', 'cmd', 'py', 'java', 'kt', 'gradle', 'properties', 'env',
        'srt', 'vtt', 'nfo',
    ],
    'text_filenames' => ['readme', 'license', 'makefile', 'dockerfile'],
    'hidden_names' => [
        '@eaDir', '#recycle', '.SynologyWorkingDirectory', '.DS_Store', '.AppleDouble',
        'Thumbs.db', 'desktop.ini', 'System Volume Information', '$RECYCLE.BIN',
    ],
    'hide_dot_files' => env('NAS_VIEWER_HIDE_DOT_FILES', true),
    'page_limit' => (int) env('NAS_VIEWER_PAGE_LIMIT', 300),
    'max_page_limit' => (int) env('NAS_VIEWER_MAX_PAGE_LIMIT', 1000),
    'text_max_bytes' => (int) env('NAS_VIEWER_TEXT_MAX_BYTES', 5 * 1024 * 1024),
    'app_version' => env('NAS_VIEWER_APP_VERSION', '2026.07.11.4'),
    'android_apk_version_code' => (int) env('NAS_VIEWER_ANDROID_APK_VERSION_CODE', 5),
    'android_apk_version_name' => env('NAS_VIEWER_ANDROID_APK_VERSION_NAME', '2026.07.11.2'),
    'android_apk_path' => env('NAS_VIEWER_ANDROID_APK_PATH', storage_path('app/nas-viewer-app.apk')),
    'tv_android_apk_version_code' => (int) env('NAS_VIEWER_TV_ANDROID_APK_VERSION_CODE', 5),
    'tv_android_apk_version_name' => env('NAS_VIEWER_TV_ANDROID_APK_VERSION_NAME', '2026.07.11.5-tv'),
    'tv_android_apk_path' => env('NAS_VIEWER_TV_ANDROID_APK_PATH', storage_path('app/nas-viewer-tv.apk')),
];

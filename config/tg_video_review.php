<?php

return [
    'root' => env('TG_VIDEO_REVIEW_ROOT', 'D:\\tg暫存'),
    'scan_lock_path' => env('TG_VIDEO_REVIEW_SCAN_LOCK_PATH', storage_path('app/tg-video-review-scan.lock')),
    'ok_subdirectory' => env('TG_VIDEO_REVIEW_OK_SUBDIRECTORY', 'ok'),
    'watermark_subdirectory' => env('TG_VIDEO_REVIEW_WATERMARK_SUBDIRECTORY', '水'),
    'ffmpeg_bin' => env('TG_VIDEO_REVIEW_FFMPEG_BIN', env('FOLDER_VIDEO_FFMPEG_BIN', 'C:\\ffmpeg\\bin\\ffmpeg.exe')),
    'ffprobe_bin' => env('TG_VIDEO_REVIEW_FFPROBE_BIN', env('FOLDER_VIDEO_FFPROBE_BIN', 'C:\\ffmpeg\\bin\\ffprobe.exe')),
    'extensions' => ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm', 'm4v', 'mpeg', 'mpg', '3gp', 'ts', 'mts', 'm2ts'],
    'contact_sheet_columns' => 5,
    'contact_sheet_rows' => 4,
    'cell_width' => 480,
    'cell_height' => 270,
];

<?php

return [
    'root' => env('FOLDER_VIDEO_ROOT', 'C:\Users\User\Pictures\train\downloads\group_3406828124_xsmyyds会员群\videos\tmp'),
    'good_subdirectory' => env('FOLDER_VIDEO_GOOD_SUBDIRECTORY', 'good'),
    'ffprobe_bin' => env('FOLDER_VIDEO_FFPROBE_BIN', 'C:\ffmpeg\bin\ffprobe.exe'),
    'index_filename' => env('FOLDER_VIDEO_INDEX_FILENAME', 'folder-video-index.json'),
    'extensions' => ['mp4', 'm4v', 'mov', 'mkv', 'webm', 'avi'],
];

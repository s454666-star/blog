<?php

return [
    'db_disk' => env('VIDEO_RERUN_SYNC_DB_DISK', 'videos'),
    'rerun_root' => env('VIDEO_RERUN_SYNC_RERUN_ROOT', 'Z:\\video(重跑)'),
    'eagle' => [
        'base_url' => env('VIDEO_RERUN_SYNC_EAGLE_BASE_URL', 'http://localhost:41595'),
        'library_name' => env('VIDEO_RERUN_SYNC_EAGLE_LIBRARY_NAME', '重跑資源'),
        'library_path' => env('VIDEO_RERUN_SYNC_EAGLE_LIBRARY_PATH', 'Z:\\重跑資源.library'),
        'fetch_limit' => (int) env('VIDEO_RERUN_SYNC_EAGLE_FETCH_LIMIT', 10000),
    ],
    'ui' => [
        'per_page' => (int) env('VIDEO_RERUN_SYNC_PER_PAGE', 40),
    ],
];

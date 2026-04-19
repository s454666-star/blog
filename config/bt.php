<?php

return [
    'crawler_enabled' => env('BT_CRAWLER_ENABLED', true),
    'run_lock_seconds' => max(60, (int) env('BT_CRAWLER_RUN_LOCK_SECONDS', 1800)),
    'detail_lock_seconds' => max(60, (int) env('BT_CRAWLER_DETAIL_LOCK_SECONDS', 900)),
];

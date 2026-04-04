<?php

namespace App\Support;

final class VideoRerunSyncSource
{
    public const DB = 'db';
    public const RERUN_DISK = 'rerun_disk';
    public const EAGLE = 'eagle';

    public static function all(): array
    {
        return [
            self::DB,
            self::RERUN_DISK,
            self::EAGLE,
        ];
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::DB => 'A. video_master(type=1)',
            self::RERUN_DISK => 'B. Z:\\video(重跑)',
            self::EAGLE => 'C. Eagle 重跑資源',
            default => $source,
        };
    }
}

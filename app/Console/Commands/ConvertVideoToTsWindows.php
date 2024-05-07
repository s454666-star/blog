<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use FFMpeg\Format\Video\X264;
use ProtoneMedia\LaravelFFMpeg\Filesystem\Media;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ConvertVideoToTsWindows extends Command
{
    protected $signature = 'video:convert-specific';
    protected $description = 'Convert a specific video file to .ts format and generate an .m3u8 playlist';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $sourcePath = "(new) (10)_chf3_prob3.mp4";
        $destinationBasePath = 'D:\\video-ts';

//        if (!file_exists($sourcePath)) {
//            Log::error("Source video file not found: {$sourcePath}");
//            return;
//        }

//        $fileNameWithoutExt = pathinfo($sourcePath, PATHINFO_FILENAME);
//        $uniqueHash = md5($sourcePath);
//        $folderPath = "{$destinationBasePath}\\{$fileNameWithoutExt}\\{$uniqueHash}";
//        $destinationPath = "{$folderPath}\\stream.m3u8";

//        if (!is_dir($folderPath)) {
//            mkdir($folderPath, 0777, true);
//        }

        try {
            $highBitrate = (new X264)->setKiloBitrate(3000);
//            $ffmpeg = FFMpeg::fromDisk('local')->open($sourcePath);

            FFMpeg::fromDisk('local-sh')
                ->open($sourcePath)
                ->exportForHLS()
                ->setSegmentLength(10)
                ->useSegmentFilenameGenerator(function ($name, $format, $key, callable $segments, callable $playlist) {
                    $segments("{$name}-{$format->getKiloBitrate()}-{$key}-%03d.ts");
                    $playlist("{$name}-{$format->getKiloBitrate()}-{$key}.m3u8");
                })
                ->addFormat($highBitrate)
                ->toDisk('local-d-sh')
                ->save("stream.m3u8");

            Log::info("Successfully converted and saved: {$destinationBasePath}");
        } catch (\Exception $e) {
            Log::error("Error converting file: {$sourcePath}. Error: " . $e->getMessage());
        }
    }
}

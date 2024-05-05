<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;
use ProtoneMedia\LaravelFFMpeg\Support\ServiceProvider;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\Format\Video\X264;

class ConvertVideoToTs extends Command
{
    protected $signature   = 'video:convert';
    protected $description = '將視頻轉換為.ts格式，生成.m3u8播放列表，包括子目錄中的文件，並將詳細日誌寫入數據庫';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $sourceDisk      = Storage::disk('videos');
        $destinationDisk = Storage::disk('converted_videos');

        $this->convertDirectory($sourceDisk, $destinationDisk, '');
    }

    private function convertDirectory($sourceDisk, $destinationDisk, $directory)
    {
        foreach ($this->allFilesGenerator($sourceDisk, $directory) as $file) {
            $existingPath = DB::table('videos_ts')->where('path', $file)->exists();

            if ($existingPath) {
                Log::info("跳過已處理的檔案: {$file}");
                continue;
            }

            $fileNameWithoutExt = pathinfo($file, PATHINFO_FILENAME);
            $folderPath         = "{$fileNameWithoutExt}/" . md5($file);

            try {
                $video         = FFMpeg::fromDisk('videos')->open($file);
                $videoDuration = $video->getDurationInSeconds();
                Log::info("視頻時長: {$videoDuration} 秒");

                $destinationPath = "{$folderPath}/stream.m3u8";

                $format = new X264('aac', 'libx264');
                $format->setKiloBitrate(1000)->setAudioChannels(2)->setAudioKbps(256);

                DB::beginTransaction();

                $video->exportForHLS()
                    ->toDisk('converted_videos')
                    ->setSegmentLength(10) // 設置每個片段長度為10秒
                    ->addFormat($format)
                    ->onProgress(function ($percentage) {
                        Log::info("轉換進度: {$percentage}%");
                    })
                    ->save($destinationPath);

                DB::table('videos_ts')->insert([
                    'video_name' => basename($file),
                    'path'       => $destinationDisk->path($destinationPath),
                    'video_time' => $videoDuration,
                    'tags'       => null,
                    'rating'     => null,
                ]);

                DB::commit();
                Log::info("成功轉換並保存: {$file}");
            }
            catch (\Exception $e) {
                DB::rollBack();
                Log::error("轉換文件出錯: {$file} 錯誤信息: " . $e->getMessage());
                Log::error("追蹤: " . $e->getTraceAsString());
                continue;
            }
        }
    }

    private function allFilesGenerator($disk, $directory): \Generator
    {
        $directories = [ $directory ];
        while ($directories) {
            $dir   = array_shift($directories);
            $files = $disk->files($dir);
            foreach ($files as $file) {
                if (!is_link($disk->path($file))) {
                    yield $file;
                }
            }

            $subdirectories = $disk->directories($dir);
            foreach ($subdirectories as $subdir) {
                if (!is_link($disk->path($subdir))) {
                    $directories[] = $subdir;
                }
            }
        }
    }
}

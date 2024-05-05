<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

class ConvertVideoToTs extends Command
{
    protected $signature   = 'video:convert';
    protected $description = 'Converts videos to .ts format, including in subdirectories and writes them to the database with detailed logging';

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
        // 生成器函數來逐個處理檔案
        foreach ($this->allFilesGenerator($sourceDisk, $directory) as $file) {
            Log::info("Processing file: {$file}");  // 添加日誌記錄處理文件
            DB::beginTransaction();                 // 開始事務
            try {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (in_array($extension, [ 'mp4', 'mov' ])) {
                    $destinationPath = preg_replace('/\.(mp4|mov)$/', '.ts', $file);
                    $video           = FFMpeg::fromDisk('videos')
                        ->open($file);

                    $videoDuration = $video->getDurationInSeconds();        // 獲取視頻長度
                    Log::info("Video duration: {$videoDuration} seconds");  // 日誌記錄視頻長度

                    $video->export()
                        ->toDisk('converted_videos')
                        ->inFormat(new \FFMpeg\Format\Video\X264('libmp3lame', 'libx264'))
                        ->save($destinationPath);

                    // 寫入數據庫
                    DB::table('videos_ts')->insert([
                        'video_name' => basename($destinationPath),
                        'path'       => 'https://s2.starweb.life/video-ts/' . $destinationPath,
                        'video_time' => $videoDuration,
                        'tags'       => null,
                        'rating'     => null,
                    ]);

                    DB::commit();                                            // 提交事務
                    Log::info("Successfully converted and saved: {$file}");  // 成功日誌
                }
            }
            catch (\Exception $e) {
                DB::rollBack();                                                                // 回滾事務
                Log::error("Error converting file: {$file} with error: " . $e->getMessage());  // 錯誤日誌
            }
        }
    }

    // 生成器函數，用於逐個獲取檔案
    private function allFilesGenerator($disk, $directory)
    {
        $directories = [$directory];

        while ($directories) {
            $dir = array_shift($directories);
            $files = $disk->files($dir);

            foreach ($files as $file) {
                yield $file;
            }

            $subdirectories = $disk->directories($dir);
            $directories = array_merge($directories, $subdirectories);
        }
    }
}

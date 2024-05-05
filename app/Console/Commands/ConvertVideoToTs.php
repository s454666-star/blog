<?php

namespace App\Console\Commands;

//use FFMpeg;
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
        // 使用 lazy 方法逐個處理檔案
        $files = $sourceDisk->allFiles($directory);

        foreach ($files as $file) {
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
}

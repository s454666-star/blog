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

            Log::info("處理檔案: {$file}");
            DB::beginTransaction();
            try {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (in_array($extension, [ 'mp4', 'mov' ])) {
                    $video         = FFMpeg::fromDisk('videos')->open($file);
                    $videoDuration = $video->getDurationInSeconds();
                    Log::info("視頻時長: {$videoDuration} 秒");

                    $destinationPath = "{$folderPath}/stream.m3u8"; // 更新 m3u8 檔案的路徑

                    $video->exportForHLS()
                        ->toDisk('converted_videos')
                        ->setSegmentLength(10) // 設置每個片段長度為10秒
                        ->save($destinationPath);

                    // 插入數據庫記錄
                    DB::table('videos_ts')->insert([
                        'video_name' => basename($file),
                        'path'       => $destinationDisk->path($destinationPath),
                        'video_time' => $videoDuration,
                        'tags'       => null,
                        'rating'     => null,
                    ]);

                    DB::commit();
                    Log::info("成功轉換並保存: {$file}");
                } else {
                    Log::warning("不支持的文件類型: {$file}");
                }
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
        Log::info("開始從目錄生成檔案: {$directory}");

        while ($directories) {
            $dir = array_shift($directories);
            Log::info("處理目錄: {$dir}");
            try {
                $files = $disk->files($dir);
                foreach ($files as $file) {
                    $fullPath = $disk->path($file);
                    if (is_link($fullPath)) {
                        Log::warning("跳過連接: {$file}");
                        continue;
                    }
                    Log::info("生成檔案: {$file}");
                    yield $file;
                }

                $subdirectories = $disk->directories($dir);
                foreach ($subdirectories as $subdir) {
                    $subFullPath = $disk->path($subdir);
                    if (!is_link($subFullPath)) {
                        Log::info("加入子目錄進行處理: {$subdir}");
                        $directories[] = $subdir;
                    } else {
                        Log::warning("跳過目錄中的連接: {$subdir}");
                    }
                }
            }
            catch (\Exception $e) {
                Log::error("訪問目錄失敗: {$dir} 錯誤信息: " . $e->getMessage());
                continue;
            }
        }

        Log::info("完成所有目錄的處理。");
    }
}

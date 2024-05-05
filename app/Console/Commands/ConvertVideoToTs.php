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
        foreach ($this->allFilesGenerator($sourceDisk, $directory) as $file) {
            $existingPath = DB::table('processed_videos')->where('path', $file)->exists();

            if ($existingPath) {
                Log::info("Skipping already processed file: {$file}");
                continue;  // Skip this file as it has already been processed
            }

            Log::info("Processing file: {$file}");
            DB::beginTransaction();
            try {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (in_array($extension, [ 'mp4', 'mov' ])) {
                    $destinationPath = preg_replace('/\.(mp4|mov)$/', '.ts', $file);
                    $video           = FFMpeg::fromDisk('videos')->open($file);

                    $videoDuration = $video->getDurationInSeconds();
                    Log::info("Video duration: {$videoDuration} seconds");

                    $video->export()
                        ->toDisk('converted_videos')
                        ->inFormat(new \FFMpeg\Format\Video\X264('libmp3lame', 'libx264'))
                        ->save($destinationPath);

                    // Insert record into database
                    DB::table('processed_videos')->insert([
                        'video_name' => basename($destinationPath),
                        'path'       => $destinationPath,
                        'video_time' => $videoDuration,
                        'tags'       => null,
                        'rating'     => null,
                    ]);

                    DB::commit();
                    Log::info("Successfully converted and saved: {$file}");
                } else {
                    Log::warning("Unsupported file type: {$file}");
                }
            }
            catch (\Exception $e) {
                DB::rollBack();
                Log::error("Error converting file: {$file} with error: " . $e->getMessage());
                continue;
            }
        }
    }

    // 生成器函數，用於逐個獲取檔案
    private function allFilesGenerator($disk, $directory)
    {
        $directories = [ $directory ];

        while ($directories) {
            $dir = array_shift($directories);
            try {
                $files = $disk->files($dir);
                foreach ($files as $file) {
                    $fullPath = $disk->path($file); // 獲得完整路徑
                    if (is_link($fullPath)) {
                        Log::warning("Skipping link: {$file}");
                        continue;
                    }
                    yield $file;
                }

                $subdirectories = $disk->directories($dir);
                foreach ($subdirectories as $subdir) {
                    $subFullPath = $disk->path($subdir); // 獲得子目錄的完整路徑
                    if (!is_link($subFullPath)) {
                        $directories[] = $subdir;
                    } else {
                        Log::warning("Skipping link in directories: {$subdir}");
                    }
                }
            }
            catch (\Exception $e) {
                Log::error("Failed to access directory: {$dir} with error: " . $e->getMessage());
                continue;
            }
        }
    }

}

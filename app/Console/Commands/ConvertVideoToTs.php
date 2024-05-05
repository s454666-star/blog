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
    protected $description = 'Converts videos to .ts format and generates an .m3u8 playlist, including in subdirectories and writes them to the database with detailed logging';

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
                Log::info("Skipping already processed file: {$file}");
                continue;
            }

            $fileNameWithoutExt = pathinfo($file, PATHINFO_FILENAME);
            $folderPath = "{$fileNameWithoutExt}/" . md5($file);

            Log::info("Processing file: {$file}");
            DB::beginTransaction();
            try {
                $extension = pathinfo($file, PATHINFO_EXTENSION);
                if (in_array($extension, ['mp4', 'mov'])) {
                    $video = FFMpeg::fromDisk('videos')->open($file);
                    $videoDuration = $video->getDurationInSeconds();
                    Log::info("Video duration: {$videoDuration} seconds");

                    $destinationPath = "{$folderPath}/stream.m3u8"; // Update path for m3u8 file

                    $video->exportForHLS()
                        ->toDisk('converted_videos')
                        ->addFormat(new \FFMpeg\Format\Video\X264(), function($media) {
                            $media->addFilter('segment_time', 10); // Each segment of 10 seconds
                        })
                        ->setTsSubDirectory('segments') // Define TS segments subdirectory
                        ->save($destinationPath);

                    // Insert record into database
                    DB::table('videos_ts')->insert([
                        'video_name' => basename($file),
                        'path'       => $destinationDisk->path($destinationPath),
                        'video_time' => $videoDuration,
                        'tags'       => null,
                        'rating'     => null,
                    ]);

                    DB::commit();
                    Log::info("Successfully converted and saved: {$file}");
                } else {
                    Log::warning("Unsupported file type: {$file}");
                }
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error("Error converting file: {$file} with error: " . $e->getMessage());
                continue;
            }
        }
    }

    private function allFilesGenerator($disk, $directory): \Generator
    {
        $directories = [$directory];
        Log::info("Starting file generation from directory: {$directory}");

        while ($directories) {
            $dir = array_shift($directories);
            Log::info("Processing directory: {$dir}");
            try {
                $files = $disk->files($dir);
                foreach ($files as $file) {
                    $fullPath = $disk->path($file);
                    if (is_link($fullPath)) {
                        Log::warning("Skipping link: {$file}");
                        continue;
                    }
                    Log::info("Yielding file: {$file}");
                    yield $file;
                }

                $subdirectories = $disk->directories($dir);
                foreach ($subdirectories as $subdir) {
                    $subFullPath = $disk->path($subdir);
                    if (!is_link($subFullPath)) {
                        Log::info("Adding subdirectory to process: {$subdir}");
                        $directories[] = $subdir;
                    } else {
                        Log::warning("Skipping link in directories: {$subdir}");
                    }
                }
            } catch (\Exception $e) {
                Log::error("Failed to access directory: {$dir} with error: " . $e->getMessage());
                continue;
            }
        }

        Log::info("Finished processing all directories.");
    }
}

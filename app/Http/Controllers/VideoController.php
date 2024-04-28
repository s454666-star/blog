<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Ensure Log facade is included

class VideoController extends Controller
{
    public function importVideos(): Response
    {
        $directory = '/mnt/nas/video1';                                       // Base directory for video files
        Log::info("Starting video import from base directory: {$directory}"); // Log the start of import
        $this->importDirectory($directory);

        return new Response([ 'message' => 'Video import process completed' ], 200);
    }

    private function importDirectory($dir)
    {
        Log::info("Accessing directory: {$dir}"); // Log when a directory is accessed
        $files = scandir($dir);

        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $fullPath = $dir . '/' . $file;
                if (is_dir($fullPath)) {
                    $this->importDirectory($fullPath);  // Recursive call for directories
                } else {
                    $this->importFile($fullPath);       // Process files
                }
            }
        }
    }

    private function importFile($filePath)
    {
        Log::info("Processing file: {$filePath}"); // Log each file being processed
        if (preg_match('/\.mp4$/', $filePath)) { // Ensuring it's an MP4 file
            $webPath = "https://s2.starweb.life/videos" . str_replace('/mnt/nas/video1', '', $filePath);
            $webPath = str_replace(' ', '%20', $webPath);

            $exists = DB::table('videos')->where('path', $webPath)->exists();
            if ($exists) {
                Log::warning("Duplicate video found at path: {$webPath}"); // Log if a duplicate is found
            } else {
                Video::create([
                    'video_name' => basename($filePath),
                    'path'       => $webPath,
                    'tags'       => null,
                    'rating'     => null
                ]);
            }
        }
    }
}

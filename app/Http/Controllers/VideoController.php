<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Response;

class VideoController extends Controller
{
    public function importVideos(): Response
    {
        $directory = '/mnt/nas/video1/TG/short'; // Base directory for video files
        $this->importDirectory($directory);

        return new Response([ 'message' => 'Video import process completed' ], 200);
    }

    private function importDirectory($dir)
    {
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
        if (preg_match('/\.mp4$/', $filePath)) { // Ensuring it's an MP4 file
            $webPath = "https://s2.starweb.life/videos/short" . str_replace('/mnt/nas/video1/TG/short', '', $filePath);
            $webPath = str_replace(' ', '%20', $webPath);

            // Check if the video already exists in the database
            if (!Video::where('path', $webPath)->exists()) {
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

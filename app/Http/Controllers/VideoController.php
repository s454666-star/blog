<?php

namespace App\Http\Controllers;

use App\Models\Video;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class VideoController extends Controller
{
    public function importVideos(): Response
    {
        $directory = '/mnt/nas/video1'; // Base directory for video files
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
            $webPath = "https://s2.starweb.life/videos" . str_replace('/mnt/nas/video1', '', $filePath);
            $webPath = str_replace(' ', '%20', $webPath);

            $exists = DB::table('videos')->whereRaw('path = ?', [ $webPath ])->exists();
            if (!$exists) {
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

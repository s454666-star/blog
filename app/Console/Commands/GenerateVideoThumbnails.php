<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use FFMpeg;

class GenerateVideoThumbnails extends Command
{
    protected $signature   = 'video:generate-thumbnails';
    protected $description = 'Generates thumbnails and screenshots for videos with missing images';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $videos = DB::table('videos_ts')->whereNull('preview_image')->orWhereNull('video_screenshot')->get();

        foreach ($videos as $video) {
            $ffmpeg    = FFMpeg\FFMpeg::create();
            $videoFile = $ffmpeg->open($video->path);
            $duration  = $videoFile->getFFProbe()->format($video->path)->get('duration');

            if (is_null($video->preview_image)) {
                $previewImage = $this->generatePreviewImage($videoFile);
                DB::table('videos_ts')->where('id', $video->id)->update([ 'preview_image' => $previewImage ]);
            }

            if (is_null($video->video_screenshot)) {
                $videoScreenshot = $this->generateVideoScreenshot($videoFile, $duration);
                DB::table('videos_ts')->where('id', $video->id)->update([ 'video_screenshot' => $videoScreenshot ]);
            }
        }
    }

    private function generatePreviewImage($video)
    {
        $frame    = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds(0));
        $tempPath = tempnam(sys_get_temp_dir(), 'preview') . '.jpg';
        $frame->save($tempPath);
        $imageData = file_get_contents($tempPath);
        unlink($tempPath);

        return base64_encode($imageData);
    }

    private function generateVideoScreenshot($video, $duration)
    {
        $frameCount = 20;
        $interval   = $duration / $frameCount;
        $rows       = 5;
        $cols       = 4;
        $width      = 200;  // Width of each thumbnail
        $height     = 800;  // Height of each thumbnail

        $montageImage = imagecreatetruecolor($width * $cols, $height * $rows);

        for ($i = 0; $i < $frameCount; $i++) {
            $frame    = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($i * $interval));
            $tempPath = tempnam(sys_get_temp_dir(), 'frame') . '.jpg';
            $frame->save($tempPath);
            $frameImage = imagecreatefromjpeg($tempPath);
            $x          = ($i % $cols) * $width;
            $y          = floor($i / $cols) * $height;
            imagecopyresampled($montageImage, $frameImage, $x, $y, 0, 0, $width, $height, imagesx($frameImage), imagesy($frameImage));
            imagedestroy($frameImage);
            unlink($tempPath);
        }

        $finalPath = tempnam(sys_get_temp_dir(), 'montage') . '.jpg';
        imagejpeg($montageImage, $finalPath, 100);
        imagedestroy($montageImage);
        $finalImageData = file_get_contents($finalPath);
        unlink($finalPath);

        return base64_encode($finalImageData);
    }
}

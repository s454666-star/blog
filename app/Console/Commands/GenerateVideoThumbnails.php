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
            if (is_null($video->preview_image)) {
                $previewImage = $this->generatePreviewImage($video->path);
                DB::table('videos_ts')->where('id', $video->id)->update([ 'preview_image' => $previewImage ]);
            }
        }
    }

    private function generatePreviewImage($url)
    {
        $ffmpeg     = FFMpeg\FFMpeg::create();
        $video      = $ffmpeg->open($url);
        $duration   = $video->getFFProbe()->format($url)->get('duration');
        $frameCount = 16;
        $interval   = $duration / $frameCount;
        $rows       = 9;
        $cols       = 4;
        $width      = 320;  // Width of each thumbnail
        $height     = 180;  // Height of each thumbnail

        $montageImage = imagecreatetruecolor($width * $cols, $height * $rows);

        for ($i = 0; $i < $frameCount; $i++) {
            $frame    = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($i * $interval));
            $tempPath = tempnam(sys_get_temp_dir(), 'frame') . '.jpg';
            $frame->save($tempPath);
            $frameImage = imagecreatefromjpeg($tempPath);
            $x          = ($i % $cols) * $width;
            $y          = floor($i / $cols) * $height;
            imagecopy($montageImage, $frameImage, $x, $y, 0, 0, $width, $height);
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

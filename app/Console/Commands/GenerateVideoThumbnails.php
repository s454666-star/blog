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
            $localPath     = $this->transformUrlToPath($video->path);
            $ffmpeg        = FFMpeg\FFMpeg::create();
            $videoFile     = $ffmpeg->open($localPath);
            $duration      = $videoFile->getFFProbe()->format($localPath)->get('duration');
            $directoryPath = dirname($localPath);

            if (is_null($video->preview_image)) {
                $previewImagePath = $directoryPath . '/preview_image.jpg';
                $this->generatePreviewImage($videoFile, $previewImagePath);
                $webPreviewUrl = $this->transformPathToUrl($previewImagePath);
                DB::table('videos_ts')->where('id', $video->id)->update([ 'preview_image' => $webPreviewUrl ]);
            }

            if (is_null($video->video_screenshot)) {
                $videoScreenshotPath = $directoryPath . '/video_screenshot.jpg';
                $this->generateVideoScreenshot($videoFile, $duration, $videoScreenshotPath);
                $webScreenshotUrl = $this->transformPathToUrl($videoScreenshotPath);
                DB::table('videos_ts')->where('id', $video->id)->update([ 'video_screenshot' => $webScreenshotUrl ]);
            }
        }
    }

    private function transformUrlToPath($url)
    {
        return str_replace('https://s2.starweb.life', '/mnt/nas', $url);
    }

    private function transformPathToUrl($path)
    {
        return str_replace('/mnt/nas', 'https://s2.starweb.life', $path);
    }

    private function generatePreviewImage($video, $outputPath)
    {
        $frame = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds(0));
        $frame->save($outputPath);
    }

    private function generateVideoScreenshot($video, $duration, $outputPath)
    {
        $frameCount = 20;
        $interval   = $duration / $frameCount;
        $rows       = 5;
        $cols       = 4;
        $width      = 200;  // Width of each thumbnail
        $height     = 800;  // Height of each thumbnail

        $montageImage = imagecreatetruecolor($width * $cols, $height * $rows);

        for ($i = 0; $i < $frameCount; $i++) {
            $currentSeconds = $i * $interval;  // Calculate current seconds for this frame
            $frame          = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($currentSeconds));
            $tempPath       = tempnam(sys_get_temp_dir(), 'frame') . '.jpg';

            if (!$frame->save($tempPath)) {
                // Log error if the frame cannot be saved
                \Log::error("Failed to save frame at {$currentSeconds} seconds to path {$tempPath}");
                continue;  // Skip this frame if save failed
            }

            if (!file_exists($tempPath)) {
                // Log error if the file does not exist
                \Log::error("File does not exist after saving: {$tempPath}");
                continue;  // Skip this frame if the file doesn't exist
            }

            $frameImage = imagecreatefromjpeg($tempPath);
            $x          = ($i % $cols) * $width;
            $y          = floor($i / $cols) * $height;
            imagecopyresampled($montageImage, $frameImage, $x, $y, 0, 0, $width, $height, imagesx($frameImage), imagesy($frameImage));
            imagedestroy($frameImage);
            unlink($tempPath);
        }

        imagejpeg($montageImage, $outputPath, 100);
        imagedestroy($montageImage);
    }


}

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
            $localPath = $this->transformUrlToPath($video->path);
            $ffmpeg    = FFMpeg\FFMpeg::create();
            try {
                $videoFile = $ffmpeg->open($localPath);
            } catch (FFMpeg\Exception\RuntimeException $e) {
                \Log::error("Unable to open video file: {$localPath}");
                continue;
            }
            $duration      = $videoFile->getFFProbe()->format($localPath)->get('duration');
            $directoryPath = dirname($localPath);

            if (is_null($video->preview_image)) {
                $previewImagePath = $directoryPath . '/preview_image.jpg';
                try {
                    $this->generatePreviewImage($videoFile, $previewImagePath);
                    $webPreviewUrl = $this->transformPathToUrl($previewImagePath);
                    DB::table('videos_ts')->where('id', $video->id)->update(['preview_image' => $webPreviewUrl]);
                } catch (FFMpeg\Exception\RuntimeException $e) {
                    \Log::error("Unable to generate preview image for video: {$localPath}");
                    continue;
                }
            }

            if (is_null($video->video_screenshot)) {
                $videoScreenshotPath = $directoryPath . '/video_screenshot.jpg';
                try {
                    $this->generateVideoScreenshot($videoFile, $duration, $videoScreenshotPath);
                    $webScreenshotUrl = $this->transformPathToUrl($videoScreenshotPath);
                    DB::table('videos_ts')->where('id', $video->id)->update(['video_screenshot' => $webScreenshotUrl]);
                } catch (FFMpeg\Exception\RuntimeException $e) {
                    \Log::error("Unable to generate video screenshot for video: {$localPath}");
                    continue;
                }
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
        $frameCount = 24;
        $interval   = $duration / $frameCount;
        $cols       = 4;
        $maxWidth   = 500;  // Maximum width of each thumbnail
        $maxHeight  = 500;  // Maximum height of each thumbnail

        // Get the video dimensions to maintain aspect ratio
        $videoDimensions = $video->getStreams()->videos()->first()->getDimensions();
        $originalWidth   = $videoDimensions->getWidth();
        $originalHeight  = $videoDimensions->getHeight();

        // Calculate the scaling factor and final thumbnail dimensions
        $scale       = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        $thumbWidth  = (int)($originalWidth * $scale);
        $thumbHeight = (int)($originalHeight * $scale);

        // Calculate the total height required for all rows
        $rows         = ceil($frameCount / $cols);
        $montageImage = imagecreatetruecolor($thumbWidth * $cols, $thumbHeight * $rows);

        for ($i = 0; $i < $frameCount; $i++) {
            $currentSeconds = $i * $interval;  // Calculate current seconds for this frame
            $frame          = $video->frame(FFMpeg\Coordinate\TimeCode::fromSeconds($currentSeconds));
            $tempPath       = tempnam(sys_get_temp_dir(), 'frame') . '.jpg';

            try {
                $frame->save($tempPath);
            } catch (FFMpeg\Exception\RuntimeException $e) {
                \Log::error("Failed to save frame at {$currentSeconds} seconds to path {$tempPath}");
                continue;
            }

            if (!file_exists($tempPath)) {
                \Log::error("File does not exist after saving: {$tempPath}");
                continue;
            }

            $frameImage = imagecreatefromjpeg($tempPath);
            $x          = ($i % $cols) * $thumbWidth;
            $y          = floor($i / $cols) * $thumbHeight;
            imagecopyresampled($montageImage, $frameImage, $x, $y, 0, 0, $thumbWidth, $thumbHeight, imagesx($frameImage), imagesy($frameImage));
            imagedestroy($frameImage);
            unlink($tempPath);
        }

        imagejpeg($montageImage, $outputPath, 100);
        imagedestroy($montageImage);
    }
}

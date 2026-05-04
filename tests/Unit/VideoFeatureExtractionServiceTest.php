<?php

namespace Tests\Unit;

use App\Services\VideoFeatureExtractionService;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

class VideoFeatureExtractionServiceTest extends TestCase
{
    public function test_dhash_ignores_near_black_pillarbox_borders(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension is required for dHash tests.');
        }

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_video_hash_' . uniqid('', true);
        File::ensureDirectoryExists($dir);

        try {
            $contentPath = $dir . DIRECTORY_SEPARATOR . 'content.jpg';
            $borderedPath = $dir . DIRECTORY_SEPARATOR . 'bordered.jpg';

            $content = imagecreatetruecolor(80, 120);
            for ($y = 0; $y < 120; $y++) {
                for ($x = 0; $x < 80; $x++) {
                    $color = imagecolorallocate(
                        $content,
                        ($x * 3 + $y) % 256,
                        ($x * 5 + $y * 2) % 256,
                        ($x + $y * 4) % 256
                    );
                    imagesetpixel($content, $x, $y, $color);
                }
            }
            imagejpeg($content, $contentPath, 95);

            $bordered = imagecreatetruecolor(120, 120);
            imagefill($bordered, 0, 0, imagecolorallocate($bordered, 0, 0, 0));
            imagecopy($bordered, $content, 20, 0, 0, 0, 80, 120);
            imagejpeg($bordered, $borderedPath, 95);

            imagedestroy($content);
            imagedestroy($bordered);

            $method = new ReflectionMethod(VideoFeatureExtractionService::class, 'computeDhashHexFromJpeg');
            $method->setAccessible(true);
            $service = new VideoFeatureExtractionService();

            $contentHash = (string) $method->invoke($service, $contentPath);
            $borderedHash = (string) $method->invoke($service, $borderedPath);

            $this->assertGreaterThanOrEqual(
                95,
                $service->hashSimilarityPercent($contentHash, $borderedHash)
            );
        } finally {
            File::deleteDirectory($dir);
        }
    }
}

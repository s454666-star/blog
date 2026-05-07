<?php

namespace Tests\Unit;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use App\Models\VideoMaster;
use App\Models\VideoScreenshot;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class VideoFeatureExtractionServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Storage::forgetDisk('videos');

        parent::tearDown();
    }

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

    public function test_refresh_keeps_new_feature_file_when_path_matches_old_frame_path(): void
    {
        $this->prepareFeatureDatabase();

        $root = storage_path('framework/testing/video-feature-refresh-' . uniqid('', true));
        File::ensureDirectoryExists($root);
        config()->set('filesystems.disks.videos.root', $root);
        Storage::forgetDisk('videos');

        try {
            $video = VideoMaster::query()->create([
                'video_name' => 'Clip.mp4',
                'video_path' => 'Clip/Clip.mp4',
                'duration' => 15.000,
            ]);

            $feature = VideoFeature::query()->create([
                'video_master_id' => $video->id,
                'video_name' => 'Clip.mp4',
                'video_path' => 'Clip/Clip.mp4',
                'directory_path' => 'Clip',
                'file_name' => 'Clip.mp4',
                'duration_seconds' => 15.000,
                'screenshot_count' => 1,
                'feature_version' => 'v1',
                'capture_rule' => '10s_x4',
            ]);

            $screenshot = VideoScreenshot::query()->create([
                'video_master_id' => $video->id,
                'screenshot_path' => 'Clip/Clip_feature_01_10s.jpg',
            ]);

            VideoFeatureFrame::query()->create([
                'video_feature_id' => $feature->id,
                'video_screenshot_id' => $screenshot->id,
                'capture_order' => 1,
                'capture_second' => 10.000,
                'screenshot_path' => 'Clip/Clip_feature_01_10s.jpg',
                'dhash_hex' => '0000000000000000',
                'dhash_prefix' => '00',
            ]);

            $absoluteFramePath = $root . DIRECTORY_SEPARATOR . 'Clip' . DIRECTORY_SEPARATOR . 'Clip_feature_01_10s.jpg';
            File::ensureDirectoryExists(dirname($absoluteFramePath));
            file_put_contents($absoluteFramePath, 'old-frame');

            $tmpDir = $root . DIRECTORY_SEPARATOR . 'tmp';
            File::ensureDirectoryExists($tmpDir);
            $tmpFrame = $tmpDir . DIRECTORY_SEPARATOR . 'frame_01.jpg';
            file_put_contents($tmpFrame, 'new-frame');

            $service = new VideoFeatureExtractionService();
            $service->persistPayloadForVideo($video, [
                'video_name' => 'Clip.mp4',
                'file_name' => 'Clip.mp4',
                'file_size_bytes' => 123,
                'duration_seconds' => 15.000,
                'file_created_at' => now(),
                'file_modified_at' => now(),
                'feature_version' => 'v2',
                'capture_rule' => '10s_x4',
                'frames' => [[
                    'capture_order' => 1,
                    'capture_second' => 10.000,
                    'suggested_filename' => 'Clip_feature_01_10s.jpg',
                    'temp_path' => $tmpFrame,
                    'dhash_hex' => '1111111111111111',
                    'dhash_prefix' => '11',
                    'frame_sha1' => sha1('new-frame'),
                    'image_width' => 320,
                    'image_height' => 180,
                ]],
            ]);

            $this->assertFileExists($absoluteFramePath);
            $this->assertSame('new-frame', file_get_contents($absoluteFramePath));
            $this->assertSame(1, VideoFeatureFrame::query()->where('video_feature_id', $feature->id)->count());
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_existing_feature_uses_probed_duration_for_expected_frame_count(): void
    {
        $this->prepareFeatureDatabase();

        $root = storage_path('framework/testing/video-feature-duration-' . uniqid('', true));
        File::ensureDirectoryExists($root);
        config()->set('filesystems.disks.videos.root', $root);
        Storage::forgetDisk('videos');

        try {
            $video = VideoMaster::query()->create([
                'video_name' => 'ShortBoundary.mp4',
                'video_path' => 'ShortBoundary/ShortBoundary.mp4',
                'duration' => 30.000,
            ]);

            $feature = VideoFeature::query()->create([
                'video_master_id' => $video->id,
                'video_name' => 'ShortBoundary.mp4',
                'video_path' => 'ShortBoundary/ShortBoundary.mp4',
                'directory_path' => 'ShortBoundary',
                'file_name' => 'ShortBoundary.mp4',
                'duration_seconds' => 29.996,
                'screenshot_count' => 2,
                'feature_version' => 'v2',
                'capture_rule' => '10s_x4',
            ]);

            foreach ([1 => 10.000, 2 => 20.000] as $order => $second) {
                VideoFeatureFrame::query()->create([
                    'video_feature_id' => $feature->id,
                    'capture_order' => $order,
                    'capture_second' => $second,
                    'screenshot_path' => sprintf('ShortBoundary/ShortBoundary_feature_%02d_%ds.jpg', $order, (int) $second),
                    'dhash_hex' => str_repeat((string) $order, 16),
                    'dhash_prefix' => str_repeat((string) $order, 2),
                ]);
            }

            $service = new class extends VideoFeatureExtractionService {
                public bool $inspected = false;

                public function inspectFile(string $absolutePath): array
                {
                    $this->inspected = true;

                    throw new RuntimeException('Existing complete feature should not be re-inspected.');
                }
            };

            $result = $service->extractForVideo($video);

            $this->assertFalse($service->inspected);
            $this->assertSame($feature->id, $result->id);
            $this->assertSame(2, $result->frames->count());
        } finally {
            File::deleteDirectory($root);
        }
    }

    private function prepareFeatureDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('video_face_screenshots');
        Schema::dropIfExists('video_screenshots');
        Schema::dropIfExists('video_feature_frames');
        Schema::dropIfExists('video_features');
        Schema::dropIfExists('video_master');

        Schema::create('video_master', function (Blueprint $table): void {
            $table->id();
            $table->string('video_name', 255)->nullable();
            $table->string('video_path', 500)->nullable();
            $table->decimal('duration', 10, 3)->default(0);
            $table->string('video_type', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('video_features', function (Blueprint $table): void {
            $table->id();
            $table->integer('video_master_id')->unique();
            $table->integer('master_face_screenshot_id')->nullable();
            $table->string('video_name', 255)->nullable();
            $table->string('video_path', 500)->nullable();
            $table->string('directory_path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->char('path_sha1', 40)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('duration_seconds', 10, 3)->default(0);
            $table->dateTime('file_created_at')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->unsignedTinyInteger('screenshot_count')->default(0);
            $table->string('feature_version', 32)->default('v1');
            $table->string('capture_rule', 64)->default('10s_x4');
            $table->dateTime('extracted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('video_feature_frames', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('video_feature_id');
            $table->integer('video_screenshot_id')->nullable();
            $table->unsignedTinyInteger('capture_order');
            $table->decimal('capture_second', 10, 3)->default(0);
            $table->string('screenshot_path', 500)->nullable();
            $table->char('dhash_hex', 16)->default('0000000000000000');
            $table->char('dhash_prefix', 2)->default('00');
            $table->char('frame_sha1', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->timestamps();
        });

        Schema::create('video_screenshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('video_master_id')->nullable();
            $table->string('screenshot_path', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('video_face_screenshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('video_screenshot_id')->nullable();
            $table->string('face_image_path', 500)->nullable();
            $table->unsignedTinyInteger('is_master')->default(0);
            $table->timestamps();
        });
    }
}

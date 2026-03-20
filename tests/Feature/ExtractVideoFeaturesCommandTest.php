<?php

namespace Tests\Feature;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use App\Models\VideoMaster;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ExtractVideoFeaturesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            $table->integer('video_master_id')->nullable();
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

    public function test_default_command_only_retries_failed_features(): void
    {
        $successfulVideo = VideoMaster::query()->create([
            'id' => 1,
            'video_name' => 'success.mp4',
            'video_path' => '\\success.mp4',
            'duration' => 120.000,
        ]);

        $failedVideo = VideoMaster::query()->create([
            'id' => 2,
            'video_name' => 'failed.mp4',
            'video_path' => '\\failed.mp4',
            'duration' => 120.000,
        ]);

        VideoMaster::query()->create([
            'id' => 3,
            'video_name' => 'untouched.mp4',
            'video_path' => '\\untouched.mp4',
            'duration' => 120.000,
        ]);

        $successfulFeature = VideoFeature::query()->create([
            'video_master_id' => $successfulVideo->id,
            'video_name' => 'success.mp4',
            'video_path' => '\\success.mp4',
            'file_name' => 'success.mp4',
            'duration_seconds' => 120.000,
            'screenshot_count' => 2,
            'feature_version' => 'v1',
            'capture_rule' => '10s_x4',
            'last_error' => null,
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $successfulFeature->id,
            'capture_order' => 1,
            'capture_second' => 10.000,
            'screenshot_path' => '\\success_01.jpg',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $successfulFeature->id,
            'capture_order' => 2,
            'capture_second' => 20.000,
            'screenshot_path' => '\\success_02.jpg',
        ]);

        $failedFeature = VideoFeature::query()->create([
            'video_master_id' => $failedVideo->id,
            'video_name' => 'failed.mp4',
            'video_path' => '\\failed.mp4',
            'file_name' => 'failed.mp4',
            'duration_seconds' => 120.000,
            'screenshot_count' => 1,
            'feature_version' => 'v1',
            'capture_rule' => '10s_x4',
            'last_error' => 'ffmpeg 擷取截圖失敗：boom',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $failedFeature->id,
            'capture_order' => 1,
            'capture_second' => 10.000,
            'screenshot_path' => '\\failed_01.jpg',
        ]);

        $service = Mockery::mock(VideoFeatureExtractionService::class);
        $service->shouldReceive('buildCapturePlan')
            ->once()
            ->with(Mockery::type('float'))
            ->andReturn([
                ['capture_order' => 1, 'label_second' => 10.0, 'capture_second' => 10.0],
                ['capture_order' => 2, 'label_second' => 20.0, 'capture_second' => 20.0],
            ]);
        $service->shouldReceive('extractForVideo')
            ->once()
            ->with(
                Mockery::on(fn ($video): bool => $video instanceof VideoMaster && $video->id === $failedVideo->id),
                true
            )
            ->andReturn($failedFeature->fresh('frames'));
        $this->app->instance(VideoFeatureExtractionService::class, $service);

        $this->artisan('video:extract-features')
            ->expectsOutputToContain('[2] 完成 failed.mp4')
            ->expectsOutputToContain('完成，processed=1 skipped=0 failed=0')
            ->assertExitCode(0);
    }

    public function test_extract_for_video_marks_last_error_when_inspect_file_fails(): void
    {
        $video = VideoMaster::query()->create([
            'id' => 10,
            'video_name' => 'broken.mp4',
            'video_path' => '\\broken.mp4',
            'duration' => 120.000,
        ]);

        $service = Mockery::mock(VideoFeatureExtractionService::class)->makePartial();
        $service->shouldReceive('resolveAbsoluteVideoPath')
            ->once()
            ->with(Mockery::on(fn ($model): bool => $model instanceof VideoMaster && $model->id === $video->id))
            ->andReturn('D:\\broken.mp4');
        $service->shouldReceive('inspectFile')
            ->once()
            ->with('D:\\broken.mp4')
            ->andThrow(new RuntimeException('ffmpeg 擷取截圖失敗：boom'));

        try {
            $service->extractForVideo($video);
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('ffmpeg 擷取截圖失敗：boom', $e->getMessage());
        }

        $feature = VideoFeature::query()->where('video_master_id', $video->id)->first();

        $this->assertNotNull($feature);
        $this->assertSame('broken.mp4', $feature->video_name);
        $this->assertSame('\\broken.mp4', $feature->video_path);
        $this->assertSame('ffmpeg 擷取截圖失敗：boom', $feature->last_error);
    }
}

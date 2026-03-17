<?php

namespace Tests\Unit;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use App\Services\VideoDuplicateDetectionService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class VideoDuplicateDetectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('video_feature_frames');
        Schema::dropIfExists('video_features');
        Schema::dropIfExists('video_master');

        Schema::create('video_master', function (Blueprint $table): void {
            $table->id();
            $table->string('video_name', 255)->nullable();
            $table->string('video_path', 500)->nullable();
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
            $table->char('dhash_hex', 16);
            $table->char('dhash_prefix', 2);
            $table->char('frame_sha1', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->timestamps();
        });
    }

    public function test_short_video_single_frame_can_match_when_similarity_reaches_threshold(): void
    {
        DB::table('video_master')->insert([
            'id' => 101,
            'video_name' => 'short.mp4',
            'video_path' => '\\short.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 101,
            'video_name' => 'short.mp4',
            'video_path' => '\\short.mp4',
            'file_name' => 'short.mp4',
            'file_size_bytes' => 1200,
            'duration_seconds' => 8.000,
            'screenshot_count' => 1,
            'capture_rule' => 'lt_10s_at_3s',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 3.000,
            'screenshot_path' => '\\short_feature_01.jpg',
            'dhash_hex' => '0011223344556677',
            'dhash_prefix' => '00',
            'frame_sha1' => str_repeat('a', 40),
        ]);

        $payload = [
            'duration_seconds' => 8.000,
            'file_size_bytes' => 1200,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 3.000,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('b', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 90, 2, 3, 15, 250);

        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame(1, $analysis['duplicate_match']['matched_frames']);
        $this->assertSame(1, $analysis['duplicate_match']['compared_frames']);
        $this->assertSame(1, $analysis['duplicate_match']['required_matches']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
    }

    public function test_analysis_keeps_best_candidate_even_when_threshold_is_not_met(): void
    {
        DB::table('video_master')->insert([
            'id' => 102,
            'video_name' => 'candidate.mp4',
            'video_path' => '\\candidate.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 102,
            'video_name' => 'candidate.mp4',
            'video_path' => '\\candidate.mp4',
            'file_name' => 'candidate.mp4',
            'file_size_bytes' => 1500,
            'duration_seconds' => 8.000,
            'screenshot_count' => 1,
            'capture_rule' => 'lt_10s_at_3s',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 3.000,
            'screenshot_path' => '\\candidate_feature_01.jpg',
            'dhash_hex' => '00ffffffffffffff',
            'dhash_prefix' => '00',
            'frame_sha1' => str_repeat('c', 40),
        ]);

        $payload = [
            'duration_seconds' => 8.000,
            'file_size_bytes' => 1500,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 3.000,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('d', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 95, 2, 3, 15, 250);

        $this->assertNull($analysis['duplicate_match']);
        $this->assertNotNull($analysis['best_result']);
        $this->assertSame(1, $analysis['best_result']['compared_frames']);
        $this->assertSame(1, $analysis['best_result']['required_matches']);
        $this->assertFalse($analysis['best_result']['passes_threshold']);
    }

    public function test_database_match_ignores_size_difference_when_frames_match(): void
    {
        DB::table('video_master')->insert([
            'id' => 103,
            'video_name' => 'size-ignored.mp4',
            'video_path' => '\\size-ignored.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 103,
            'video_name' => 'size-ignored.mp4',
            'video_path' => '\\size-ignored.mp4',
            'file_name' => 'size-ignored.mp4',
            'file_size_bytes' => 5000,
            'duration_seconds' => 8.000,
            'screenshot_count' => 1,
            'capture_rule' => 'lt_10s_at_3s',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 3.000,
            'screenshot_path' => '\\size_ignored_feature_01.jpg',
            'dhash_hex' => '0011223344556677',
            'dhash_prefix' => '00',
            'frame_sha1' => str_repeat('e', 40),
        ]);

        $payload = [
            'duration_seconds' => 8.000,
            'file_size_bytes' => 1000,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 3.000,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('f', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 90, 2, 3, 15, 250);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
        $this->assertSame(4000, $analysis['duplicate_match']['file_size_delta_bytes']);
    }

    public function test_specific_feature_analysis_no_longer_blocks_by_size_difference(): void
    {
        DB::table('video_master')->insert([
            'id' => 104,
            'video_name' => 'size-blocked.mp4',
            'video_path' => '\\size-blocked.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 104,
            'video_name' => 'size-blocked.mp4',
            'video_path' => '\\size-blocked.mp4',
            'file_name' => 'size-blocked.mp4',
            'file_size_bytes' => 5000,
            'duration_seconds' => 8.000,
            'screenshot_count' => 1,
            'capture_rule' => 'lt_10s_at_3s',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 3.000,
            'screenshot_path' => '\\size_blocked_feature_01.jpg',
            'dhash_hex' => '0011223344556677',
            'dhash_prefix' => '00',
            'frame_sha1' => str_repeat('e', 40),
        ]);

        $payload = [
            'duration_seconds' => 8.000,
            'file_size_bytes' => 1000,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 3.000,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('f', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeSpecificFeatureMatch($payload, $feature, 90, 2, 3, 15);

        $this->assertTrue($analysis['candidate_gate']['eligible']);
        $this->assertFalse($analysis['candidate_gate']['size_filter_applied']);
        $this->assertNull($analysis['candidate_gate']['size_within_window']);
        $this->assertSame(1, $analysis['candidate_gate']['payload_prefix_count']);
        $this->assertSame(1, $analysis['candidate_gate']['feature_prefix_count']);
        $this->assertNull($analysis['candidate_gate']['required_size_percent_to_pass']);
        $this->assertTrue($analysis['candidate_gate']['size_gate_ignored']);
        $this->assertNotNull($analysis['compare_result']);
        $this->assertTrue($analysis['compare_result']['passes_threshold']);
        $this->assertNotNull($analysis['duplicate_match']);
    }
}

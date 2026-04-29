<?php

namespace Tests\Unit;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use App\Models\VideoMaster;
use App\Services\VideoDuplicateDetectionService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class VideoDuplicateDetectionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
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
            $table->decimal('duration', 10, 3)->nullable();
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

    public function test_single_frame_database_match_can_fall_back_to_duration_when_prefix_differs(): void
    {
        DB::table('video_master')->insert([
            'id' => 105,
            'video_name' => 'prefix-fallback.mp4',
            'video_path' => '\\prefix-fallback.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 105,
            'video_name' => 'prefix-fallback.mp4',
            'video_path' => '\\prefix-fallback.mp4',
            'file_name' => 'prefix-fallback.mp4',
            'file_size_bytes' => 5000,
            'duration_seconds' => 10.050,
            'screenshot_count' => 1,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 9.800,
            'screenshot_path' => '\\prefix_fallback_feature_01.jpg',
            'dhash_hex' => '1f0d1814d4cc7bd3',
            'dhash_prefix' => '1f',
            'frame_sha1' => str_repeat('1', 40),
        ]);

        $payload = [
            'duration_seconds' => 10.077,
            'file_size_bytes' => 1000,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 9.827,
                'dhash_hex' => '0f181cd494cc7b53',
                'dhash_prefix' => '0f',
                'frame_sha1' => str_repeat('2', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 80, 2, 3, 15, 250);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame($feature->id, $analysis['duplicate_match']['feature']->id);
        $this->assertSame(85.0, $analysis['duplicate_match']['similarity_percent']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
    }

    public function test_short_video_single_frame_can_use_legacy_candidate_second_when_db_feature_was_extracted_earlier(): void
    {
        DB::table('video_master')->insert([
            'id' => 108,
            'video_name' => 'legacy-short.mp4',
            'video_path' => '\\legacy-short.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 108,
            'video_name' => 'legacy-short.mp4',
            'video_path' => '\\legacy-short.mp4',
            'file_name' => 'legacy-short.mp4',
            'file_size_bytes' => 39246269,
            'duration_seconds' => 8.733,
            'screenshot_count' => 1,
            'capture_rule' => 'lt_10s_at_3s',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 2.000,
            'screenshot_path' => '\\legacy_short_feature_01.jpg',
            'dhash_hex' => '7c7060676e6c6c0f',
            'dhash_prefix' => '7c',
            'frame_sha1' => str_repeat('5', 40),
        ]);

        $payload = [
            'absolute_path' => 'C:\\incoming\\legacy-short.mp4',
            'tmp_dir' => storage_path('app/video_features/tmp/test-legacy-short'),
            'duration_seconds' => 8.733,
            'file_size_bytes' => 3274711,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 3.000,
                'dhash_hex' => '68606a686c6ced1f',
                'dhash_prefix' => '68',
                'frame_sha1' => str_repeat('6', 40),
            ]],
        ];

        $extractionService = new class extends VideoFeatureExtractionService
        {
            public function inspectFrameAtSecond(
                string $absolutePath,
                float $captureSecond,
                string $tmpDir,
                int $captureOrder = 1
            ): array {
                return [
                    'capture_order' => $captureOrder,
                    'capture_second' => 2.000,
                    'dhash_hex' => 'fc3420e76e6c4c0f',
                    'dhash_prefix' => 'fc',
                    'frame_sha1' => str_repeat('7', 40),
                ];
            }
        };

        $service = new VideoDuplicateDetectionService($extractionService);
        $analysis = $service->analyzeDatabaseMatch($payload, 80, 2, 3, 15, 250);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame($feature->id, $analysis['duplicate_match']['feature']->id);
        $this->assertSame(90.0, $analysis['duplicate_match']['similarity_percent']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
        $this->assertSame(2.0, $analysis['duplicate_match']['frame_matches'][0]['capture_second']);
        $this->assertSame(2.0, $analysis['duplicate_match']['frame_matches'][0]['matched_capture_second']);
    }

    public function test_single_frame_specific_feature_analysis_marks_prefix_gate_as_bypassed(): void
    {
        DB::table('video_master')->insert([
            'id' => 106,
            'video_name' => 'prefix-gate-bypass.mp4',
            'video_path' => '\\prefix-gate-bypass.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 106,
            'video_name' => 'prefix-gate-bypass.mp4',
            'video_path' => '\\prefix-gate-bypass.mp4',
            'file_name' => 'prefix-gate-bypass.mp4',
            'file_size_bytes' => 5000,
            'duration_seconds' => 10.050,
            'screenshot_count' => 1,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 9.800,
            'screenshot_path' => '\\prefix_gate_bypass_feature_01.jpg',
            'dhash_hex' => '1f0d1814d4cc7bd3',
            'dhash_prefix' => '1f',
            'frame_sha1' => str_repeat('3', 40),
        ]);

        $payload = [
            'duration_seconds' => 10.077,
            'file_size_bytes' => 1000,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 9.827,
                'dhash_hex' => '0f181cd494cc7b53',
                'dhash_prefix' => '0f',
                'frame_sha1' => str_repeat('4', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeSpecificFeatureMatch($payload, $feature, 80, 2, 3, 15);

        $this->assertTrue($analysis['candidate_gate']['eligible']);
        $this->assertTrue($analysis['candidate_gate']['prefix_gate_bypassed']);
        $this->assertSame([], $analysis['candidate_gate']['reasons']);
        $this->assertNotNull($analysis['compare_result']);
        $this->assertTrue($analysis['compare_result']['passes_threshold']);
    }

    public function test_multi_frame_database_match_can_fall_back_to_duration_when_prefixes_do_not_overlap(): void
    {
        DB::table('video_master')->insert([
            'id' => 107,
            'video_name' => 'duration-fallback.mp4',
            'video_path' => '\\duration-fallback.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 107,
            'video_name' => 'duration-fallback.mp4',
            'video_path' => '\\duration-fallback.mp4',
            'file_name' => 'duration-fallback.mp4',
            'file_size_bytes' => 409117139,
            'duration_seconds' => 180.117,
            'screenshot_count' => 4,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
        ]);

        foreach ([
            [1, 10.000, '26e6c06125534b87', '26'],
            [2, 20.000, '07c6c6e161d54b07', '07'],
            [3, 30.000, '16cec6c326c0430f', '16'],
            [4, 40.000, '07c6c0c410135145', '07'],
        ] as [$order, $second, $hex, $prefix]) {
            VideoFeatureFrame::query()->create([
                'video_feature_id' => $feature->id,
                'capture_order' => $order,
                'capture_second' => $second,
                'screenshot_path' => sprintf('\\duration_fallback_feature_%02d.jpg', $order),
                'dhash_hex' => $hex,
                'dhash_prefix' => $prefix,
                'frame_sha1' => str_repeat((string) $order, 40),
            ]);
        }

        $payload = [
            'duration_seconds' => 180.033,
            'file_size_bytes' => 68886379,
            'frames' => [
                [
                    'capture_order' => 1,
                    'capture_second' => 10.000,
                    'dhash_hex' => '6666e0e0655642c7',
                    'dhash_prefix' => '66',
                    'frame_sha1' => str_repeat('a', 40),
                ],
                [
                    'capture_order' => 2,
                    'capture_second' => 20.000,
                    'dhash_hex' => '0bc6c2e1a5534b87',
                    'dhash_prefix' => '0b',
                    'frame_sha1' => str_repeat('b', 40),
                ],
                [
                    'capture_order' => 3,
                    'capture_second' => 30.000,
                    'dhash_hex' => '0666c2e136cd450f',
                    'dhash_prefix' => '06',
                    'frame_sha1' => str_repeat('c', 40),
                ],
                [
                    'capture_order' => 4,
                    'capture_second' => 40.000,
                    'dhash_hex' => '03c680c411135145',
                    'dhash_prefix' => '03',
                    'frame_sha1' => str_repeat('d', 40),
                ],
            ],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 80, 2, 3, 15, 250);
        $specificAnalysis = $service->analyzeSpecificFeatureMatch($payload, $feature, 80, 2, 3, 15);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame($feature->id, $analysis['duplicate_match']['feature']->id);
        $this->assertSame(85.0, $analysis['duplicate_match']['similarity_percent']);
        $this->assertSame(3, $analysis['duplicate_match']['matched_frames']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);

        $this->assertTrue($specificAnalysis['candidate_gate']['eligible']);
        $this->assertTrue($specificAnalysis['candidate_gate']['prefix_gate_bypassed']);
        $this->assertSame([], $specificAnalysis['candidate_gate']['shared_prefixes']);
        $this->assertSame([], $specificAnalysis['candidate_gate']['reasons']);
    }

    public function test_two_frame_database_match_allows_strong_partial_when_duration_is_tight(): void
    {
        DB::table('video_master')->insert([
            'id' => 110,
            'video_name' => 'selfie.mp4',
            'video_path' => '\\selfie.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 110,
            'video_name' => 'selfie.mp4',
            'video_path' => '\\selfie.mp4',
            'file_name' => 'selfie.mp4',
            'file_size_bytes' => 20784501,
            'duration_seconds' => 28.073,
            'screenshot_count' => 2,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
        ]);

        foreach ([
            [1, 10.000, 'f8d0d5d8d9dc98d5', 'f8'],
            [2, 20.000, '70f1f0e1e4c9e9f8', '70'],
        ] as [$order, $second, $hex, $prefix]) {
            VideoFeatureFrame::query()->create([
                'video_feature_id' => $feature->id,
                'capture_order' => $order,
                'capture_second' => $second,
                'screenshot_path' => sprintf('\\selfie_feature_%02d.jpg', $order),
                'dhash_hex' => $hex,
                'dhash_prefix' => $prefix,
                'frame_sha1' => str_repeat((string) $order, 40),
            ]);
        }

        $payload = [
            'duration_seconds' => 28.093,
            'file_size_bytes' => 3520129,
            'frames' => [
                [
                    'capture_order' => 1,
                    'capture_second' => 10.000,
                    'dhash_hex' => 'fad8d8d8c8dc9cd4',
                    'dhash_prefix' => 'fa',
                    'frame_sha1' => str_repeat('a', 40),
                ],
                [
                    'capture_order' => 2,
                    'capture_second' => 20.000,
                    'dhash_hex' => 'd0f1e8d9dcd4bce8',
                    'dhash_prefix' => 'd0',
                    'frame_sha1' => str_repeat('b', 40),
                ],
            ],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 80, 2, 3, 15, 250);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame($feature->id, $analysis['duplicate_match']['feature']->id);
        $this->assertSame(77.5, $analysis['duplicate_match']['similarity_percent']);
        $this->assertSame(1, $analysis['duplicate_match']['matched_frames']);
        $this->assertSame(2, $analysis['duplicate_match']['compared_frames']);
        $this->assertSame(1, $analysis['duplicate_match']['required_matches']);
        $this->assertSame('two_frame_strong_partial', $analysis['duplicate_match']['match_rule']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
    }

    public function test_two_frame_database_match_still_fails_when_second_frame_is_too_different(): void
    {
        DB::table('video_master')->insert([
            'id' => 111,
            'video_name' => 'different.mp4',
            'video_path' => '\\different.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 111,
            'video_name' => 'different.mp4',
            'video_path' => '\\different.mp4',
            'file_name' => 'different.mp4',
            'file_size_bytes' => 20784501,
            'duration_seconds' => 28.073,
            'screenshot_count' => 2,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
        ]);

        foreach ([
            [1, 10.000, 'f8d0d5d8d9dc98d5', 'f8'],
            [2, 20.000, '70f1f0e1e4c9e9f8', '70'],
        ] as [$order, $second, $hex, $prefix]) {
            VideoFeatureFrame::query()->create([
                'video_feature_id' => $feature->id,
                'capture_order' => $order,
                'capture_second' => $second,
                'screenshot_path' => sprintf('\\different_feature_%02d.jpg', $order),
                'dhash_hex' => $hex,
                'dhash_prefix' => $prefix,
                'frame_sha1' => str_repeat((string) $order, 40),
            ]);
        }

        $payload = [
            'duration_seconds' => 28.093,
            'file_size_bytes' => 3520129,
            'frames' => [
                [
                    'capture_order' => 1,
                    'capture_second' => 10.000,
                    'dhash_hex' => 'fad8d8d8c8dc9cd4',
                    'dhash_prefix' => 'fa',
                    'frame_sha1' => str_repeat('a', 40),
                ],
                [
                    'capture_order' => 2,
                    'capture_second' => 20.000,
                    'dhash_hex' => '0000000000000000',
                    'dhash_prefix' => '00',
                    'frame_sha1' => str_repeat('b', 40),
                ],
            ],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeDatabaseMatch($payload, 80, 2, 3, 15, 250);

        $this->assertNull($analysis['duplicate_match']);
        $this->assertNotNull($analysis['best_result']);
        $this->assertSame(1, $analysis['best_result']['matched_frames']);
        $this->assertSame(2, $analysis['best_result']['compared_frames']);
        $this->assertSame(2, $analysis['best_result']['required_matches']);
        $this->assertSame('standard_min_match', $analysis['best_result']['match_rule']);
        $this->assertFalse($analysis['best_result']['passes_threshold']);
    }

    public function test_database_match_repairs_incomplete_feature_when_source_file_exists(): void
    {
        DB::table('video_master')->insert([
            'id' => 112,
            'video_name' => 'repairable.mp4',
            'video_path' => '\\repairable.mp4',
            'duration' => 91.916,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 112,
            'video_name' => 'repairable.mp4',
            'video_path' => '\\repairable.mp4',
            'file_name' => 'repairable.mp4',
            'duration_seconds' => 91.916,
            'screenshot_count' => 0,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'last_error' => 'ffprobe 失敗：',
        ]);

        $repairDir = storage_path('framework/testing/video-duplicate-repair');
        File::ensureDirectoryExists($repairDir);
        $sourcePath = $repairDir . DIRECTORY_SEPARATOR . 'repairable.mp4';
        file_put_contents($sourcePath, 'video-placeholder');

        $extractionService = new class($sourcePath) extends VideoFeatureExtractionService {
            public int $repairCalls = 0;

            public function __construct(private readonly string $sourcePath)
            {
            }

            public function resolveAbsoluteVideoPath(VideoMaster $video): string
            {
                return $this->sourcePath;
            }

            public function extractForVideo(VideoMaster $video, bool $refresh = false): VideoFeature
            {
                $this->repairCalls++;

                $feature = VideoFeature::query()->firstOrNew([
                    'video_master_id' => $video->id,
                ]);
                $feature->fill([
                    'video_name' => 'repairable.mp4',
                    'video_path' => '\\repairable.mp4',
                    'file_name' => 'repairable.mp4',
                    'file_size_bytes' => 123456,
                    'duration_seconds' => 91.916,
                    'screenshot_count' => 4,
                    'capture_rule' => '10s_x4',
                    'feature_version' => 'v1',
                    'last_error' => null,
                ]);
                $feature->save();
                $feature->frames()->delete();

                foreach ([
                    [1, 10.000, '193424ecc4241bf0', '19'],
                    [2, 20.000, '01013169c804531a', '01'],
                    [3, 30.000, '010101418482d431', '01'],
                    [4, 40.000, '0301010e1f32a673', '03'],
                ] as [$order, $second, $hex, $prefix]) {
                    VideoFeatureFrame::query()->create([
                        'video_feature_id' => $feature->id,
                        'capture_order' => $order,
                        'capture_second' => $second,
                        'screenshot_path' => sprintf('\\repairable_feature_%02d.jpg', $order),
                        'dhash_hex' => $hex,
                        'dhash_prefix' => $prefix,
                        'frame_sha1' => str_repeat((string) $order, 40),
                    ]);
                }

                return $feature->fresh('frames');
            }
        };

        $payload = [
            'duration_seconds' => 91.905,
            'file_size_bytes' => 195151520,
            'frames' => [
                [
                    'capture_order' => 1,
                    'capture_second' => 10.000,
                    'dhash_hex' => '193424e4e4341af0',
                    'dhash_prefix' => '19',
                    'frame_sha1' => str_repeat('a', 40),
                ],
                [
                    'capture_order' => 2,
                    'capture_second' => 20.000,
                    'dhash_hex' => '01003068c804d61b',
                    'dhash_prefix' => '01',
                    'frame_sha1' => str_repeat('b', 40),
                ],
                [
                    'capture_order' => 3,
                    'capture_second' => 30.000,
                    'dhash_hex' => '030000408482d431',
                    'dhash_prefix' => '03',
                    'frame_sha1' => str_repeat('c', 40),
                ],
                [
                    'capture_order' => 4,
                    'capture_second' => 40.000,
                    'dhash_hex' => '0300000e1f32a673',
                    'dhash_prefix' => '03',
                    'frame_sha1' => str_repeat('d', 40),
                ],
            ],
        ];

        $service = new VideoDuplicateDetectionService($extractionService);
        $analysis = $service->analyzeDatabaseMatch($payload, 80, 2, 3, 15, 250);

        $this->assertSame(1, $extractionService->repairCalls);
        $this->assertSame(1, $analysis['repaired_database_feature_count']);
        $this->assertSame([$feature->id], $analysis['repaired_database_feature_ids']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame($feature->id, $analysis['duplicate_match']['feature']->id);
        $this->assertSame(92.75, $analysis['duplicate_match']['similarity_percent']);
        $this->assertSame(4, $analysis['duplicate_match']['matched_frames']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);

        File::deleteDirectory($repairDir);
    }

    public function test_reference_snapshot_match_can_detect_duplicate_without_db_feature_row(): void
    {
        $payload = [
            'absolute_path' => 'C:\\incoming\\source.mp4',
            'duration_seconds' => 13.1,
            'file_size_bytes' => 1000,
            'frames' => [
                [
                    'capture_order' => 1,
                    'capture_second' => 10.0,
                    'dhash_hex' => '0011223344556677',
                    'dhash_prefix' => '00',
                    'frame_sha1' => str_repeat('a', 40),
                ],
                [
                    'capture_order' => 2,
                    'capture_second' => 20.0,
                    'dhash_hex' => '8899aabbccddeeff',
                    'dhash_prefix' => '88',
                    'frame_sha1' => str_repeat('b', 40),
                ],
            ],
        ];

        $referenceSnapshots = [[
            'absolute_path' => 'C:\\Users\\User\\Videos\\暫\\kept.mp4',
            'video_name' => 'kept.mp4',
            'file_name' => 'kept.mp4',
            'file_size_bytes' => 1001,
            'duration_seconds' => 13.08,
            'feature_version' => 'v1',
            'capture_rule' => '10s_x4',
            'frames' => [
                [
                    'capture_order' => 1,
                    'capture_second' => 10.0,
                    'dhash_hex' => '0011223344556677',
                    'dhash_prefix' => '00',
                    'frame_sha1' => str_repeat('c', 40),
                ],
                [
                    'capture_order' => 2,
                    'capture_second' => 20.0,
                    'dhash_hex' => '8899aabbccddeeff',
                    'dhash_prefix' => '88',
                    'frame_sha1' => str_repeat('d', 40),
                ],
            ],
        ]];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $analysis = $service->analyzeReferenceSnapshotsMatch($payload, $referenceSnapshots, 80, 2, 3, 15, 250);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame(100.0, $analysis['duplicate_match']['similarity_percent']);
        $this->assertSame(2, $analysis['duplicate_match']['matched_frames']);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
        $this->assertSame(
            'C:\\Users\\User\\Videos\\暫\\kept.mp4',
            $analysis['duplicate_match']['feature_snapshot']['absolute_path']
        );
        $this->assertNull($analysis['duplicate_match']['feature']->id);
    }

    public function test_prepared_reference_snapshot_index_can_be_reused_and_appended(): void
    {
        $payload = [
            'absolute_path' => 'C:\\incoming\\source.mp4',
            'duration_seconds' => 13.1,
            'file_size_bytes' => 1000,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 10.0,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('a', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());
        $preparedIndex = $service->prepareReferenceSnapshotIndex([[
            'absolute_path' => 'C:\\Users\\User\\Videos\\暫\\existing.mp4',
            'video_name' => 'existing.mp4',
            'file_name' => 'existing.mp4',
            'file_size_bytes' => 1001,
            'duration_seconds' => 13.08,
            'feature_version' => 'v1',
            'capture_rule' => '10s_x4',
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 10.0,
                'dhash_hex' => '8899aabbccddeeff',
                'dhash_prefix' => '88',
                'frame_sha1' => str_repeat('b', 40),
            ]],
        ]]);

        $preparedIndex = $service->appendPreparedReferenceSnapshot($preparedIndex, [
            'absolute_path' => 'C:\\Users\\User\\Videos\\暫\\new.mp4',
            'video_name' => 'new.mp4',
            'file_name' => 'new.mp4',
            'file_size_bytes' => 1000,
            'duration_seconds' => 13.1,
            'feature_version' => 'v1',
            'capture_rule' => '10s_x4',
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 10.0,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('c', 40),
            ]],
        ]);

        $analysis = $service->analyzePreparedReferenceSnapshotsMatch($payload, $preparedIndex, 80, 2, 3, 15, 250);

        $this->assertSame(2, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame('C:\\Users\\User\\Videos\\暫\\new.mp4', $analysis['duplicate_match']['feature_snapshot']['absolute_path']);
        $this->assertSame(100.0, $analysis['duplicate_match']['similarity_percent']);
    }

    public function test_database_candidate_ids_are_reused_from_cache_for_identical_payloads(): void
    {
        DB::table('video_master')->insert([
            'id' => 109,
            'video_name' => 'cached-candidate.mp4',
            'video_path' => '\\cached-candidate.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 109,
            'video_name' => 'cached-candidate.mp4',
            'video_path' => '\\cached-candidate.mp4',
            'file_name' => 'cached-candidate.mp4',
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
            'screenshot_path' => '\\cached_candidate_feature_01.jpg',
            'dhash_hex' => '0011223344556677',
            'dhash_prefix' => '00',
            'frame_sha1' => str_repeat('9', 40),
        ]);

        $payload = [
            'duration_seconds' => 8.000,
            'file_size_bytes' => 1200,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 3.000,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('8', 40),
            ]],
        ];

        $service = new VideoDuplicateDetectionService(new VideoFeatureExtractionService());

        DB::connection()->enableQueryLog();
        DB::flushQueryLog();
        $service->analyzeDatabaseMatch($payload, 90, 2, 3, 15, 250);
        $firstQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $service->analyzeDatabaseMatch($payload, 90, 2, 3, 15, 250);
        $secondQueryCount = count(DB::getQueryLog());
        DB::connection()->disableQueryLog();

        $this->assertGreaterThan($secondQueryCount, $firstQueryCount);
    }
}

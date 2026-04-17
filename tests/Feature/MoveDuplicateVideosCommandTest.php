<?php

namespace Tests\Feature;

use App\Models\ExternalVideoDuplicateLog;
use App\Models\ExternalVideoDuplicateMatch;
use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use App\Services\ExternalVideoDuplicateService;
use App\Services\ReferenceVideoFeatureIndexService;
use App\Services\VideoDuplicateDetectionService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MoveDuplicateVideosCommandTest extends TestCase
{
    private string $tempDir;

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

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_move_duplicate_' . uniqid('', true);
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_manual_feature_mode_moves_duplicate_and_persists_match(): void
    {
        DB::table('video_master')->insert([
            'id' => 4262,
            'video_name' => 'db-video.mp4',
            'video_path' => '\\db-video.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $feature = VideoFeature::query()->create([
            'video_master_id' => 4262,
            'video_name' => 'db-video.mp4',
            'video_path' => '\\db-video.mp4',
            'file_name' => 'db-video.mp4',
            'file_size_bytes' => 38637813,
            'duration_seconds' => 13.119,
            'screenshot_count' => 1,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
        ]);

        VideoFeatureFrame::query()->create([
            'video_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 10.000,
            'screenshot_path' => '\\db-video_feature_01_10s.jpg',
            'dhash_hex' => '1d1737170f0f9792',
            'dhash_prefix' => '1d',
            'frame_sha1' => str_repeat('a', 40),
        ]);

        $sourcePath = $this->tempDir . DIRECTORY_SEPARATOR . 'IMG_0488.mp4';
        file_put_contents($sourcePath, 'duplicate-video');

        $payload = [
            'absolute_path' => $sourcePath,
            'video_name' => 'IMG_0488.mp4',
            'file_name' => 'IMG_0488.mp4',
            'file_size_bytes' => 10082926,
            'duration_seconds' => 13.100,
            'file_created_at' => now(),
            'file_modified_at' => now(),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'frames' => [[
                'capture_order' => 1,
                'label_second' => 10.000,
                'capture_second' => 10.000,
                'dhash_hex' => '1d1737170f0f9792',
                'dhash_prefix' => '1d',
                'frame_sha1' => str_repeat('b', 40),
                'image_width' => 1072,
                'image_height' => 1920,
            ]],
        ];

        $compareResult = [
            'feature' => $feature->fresh(['frames', 'videoMaster']),
            'similarity_percent' => 100.0,
            'matched_frames' => 1,
            'compared_frames' => 1,
            'required_matches' => 1,
            'passes_threshold' => true,
            'frame_matches' => [[
                'capture_order' => 1,
                'capture_second' => 10.000,
                'matched_video_feature_frame_id' => 1,
                'matched_capture_second' => 10.000,
                'payload_dhash_hex' => '1d1737170f0f9792',
                'matched_dhash_hex' => '1d1737170f0f9792',
                'similarity_percent' => 100.0,
                'is_threshold_match' => true,
            ]],
            'duration_delta_seconds' => 0.019,
            'file_size_delta_bytes' => 28554887,
        ];

        $analysis = [
            'feature' => $feature,
            'candidate_gate' => [
                'eligible' => true,
                'payload_duration_seconds' => 13.100,
                'feature_duration_seconds' => 13.119,
            ],
            'compare_result' => $compareResult,
            'best_result' => $compareResult,
            'duplicate_match' => $compareResult,
            'payload_frame_count' => 1,
            'requested_min_match' => 2,
        ];

        $featureExtractionService = Mockery::mock(VideoFeatureExtractionService::class);
        $featureExtractionService->shouldReceive('inspectFile')
            ->once()
            ->with($sourcePath)
            ->andReturn($payload);
        $featureExtractionService->shouldReceive('resolveAbsoluteVideoPath')
            ->once()
            ->andReturn('D:\\video\\db-video.mp4');
        $featureExtractionService->shouldReceive('cleanupPayload')
            ->once()
            ->with($payload);
        $this->app->instance(VideoFeatureExtractionService::class, $featureExtractionService);

        $duplicateDetectionService = Mockery::mock(VideoDuplicateDetectionService::class);
        $duplicateDetectionService->shouldReceive('analyzeSpecificFeatureMatch')
            ->once()
            ->with(
                $payload,
                Mockery::on(fn ($model): bool => $model instanceof VideoFeature && $model->id === $feature->id),
                80,
                2,
                3,
                15
            )
            ->andReturn($analysis);
        $this->app->instance(VideoDuplicateDetectionService::class, $duplicateDetectionService);

        $matchRecord = new ExternalVideoDuplicateMatch();
        $matchRecord->id = 123;

        $logRecord = new ExternalVideoDuplicateLog();
        $logRecord->id = 456;

        $expectedDuplicateDir = $this->tempDir . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $capturedDestinationPath = null;

        $externalVideoDuplicateService = Mockery::mock(ExternalVideoDuplicateService::class);
        $externalVideoDuplicateService->shouldReceive('persistMatchResult')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                array $passedMatch,
                string $passedSourcePath,
                string $passedDuplicatePath,
                array $options
            ) use ($payload, $sourcePath, $expectedDuplicateDir, &$capturedDestinationPath): bool {
                $capturedDestinationPath = $passedDuplicatePath;

                return $passedPayload === $payload
                    && $passedMatch['similarity_percent'] === 100.0
                    && $passedSourcePath === $sourcePath
                    && str_starts_with($passedDuplicatePath, $expectedDuplicateDir)
                    && ($options['duplicate_directory_path'] ?? null) === $expectedDuplicateDir;
            })
            ->andReturn($matchRecord);
        $externalVideoDuplicateService->shouldReceive('persistComparisonLog')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                ?array $passedAnalysis,
                string $passedSourcePath,
                array $options
            ) use ($payload, $sourcePath, &$capturedDestinationPath): bool {
                return $passedPayload === $payload
                    && is_array($passedAnalysis)
                    && $passedSourcePath === $sourcePath
                    && ($options['external_video_duplicate_match_id'] ?? null) === 123
                    && ($options['duplicate_file_path'] ?? null) === $capturedDestinationPath
                    && ($options['operation_status'] ?? null) === 'match_moved'
                    && ($options['is_duplicate_detected'] ?? null) === true;
            })
            ->andReturn($logRecord);
        $this->app->instance(ExternalVideoDuplicateService::class, $externalVideoDuplicateService);

        $this->artisan('video:move-duplicates', [
            'path' => $this->tempDir,
            '--video-feature-id' => $feature->id,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($sourcePath);
        $this->assertNotNull($capturedDestinationPath);
        $this->assertFileExists($capturedDestinationPath);
    }

    public function test_command_moves_file_when_reference_index_match_exists_after_db_miss(): void
    {
        $sourcePath = $this->tempDir . DIRECTORY_SEPARATOR . 'incoming.mp4';
        $referenceDir = $this->tempDir . DIRECTORY_SEPARATOR . 'reference';
        $referenceMatchPath = $referenceDir . DIRECTORY_SEPARATOR . 'kept.mp4';

        File::ensureDirectoryExists($referenceDir);
        file_put_contents($sourcePath, 'incoming-video');
        file_put_contents($referenceMatchPath, 'reference-video');

        $payload = [
            'absolute_path' => $sourcePath,
            'video_name' => 'incoming.mp4',
            'file_name' => 'incoming.mp4',
            'file_size_bytes' => 1000,
            'duration_seconds' => 13.1,
            'file_created_at' => now(),
            'file_modified_at' => now(),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 10.0,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('a', 40),
            ]],
        ];

        $databaseAnalysis = [
            'best_result' => null,
            'duplicate_match' => null,
            'candidate_count' => 0,
            'payload_frame_count' => 1,
            'requested_min_match' => 1,
        ];
        $referenceAnalysis = [
            'best_result' => [
                'score' => 1100,
                'similarity_percent' => 100.0,
                'matched_frames' => 1,
                'compared_frames' => 1,
            ],
            'duplicate_match' => [
                'score' => 1100,
                'similarity_percent' => 100.0,
                'matched_frames' => 1,
                'compared_frames' => 1,
                'passes_threshold' => true,
                'feature_snapshot' => [
                    'absolute_path' => $referenceMatchPath,
                ],
            ],
            'candidate_count' => 1,
            'payload_frame_count' => 1,
            'requested_min_match' => 1,
        ];

        $featureExtractionService = Mockery::mock(VideoFeatureExtractionService::class);
        $featureExtractionService->shouldReceive('inspectFile')
            ->once()
            ->with($sourcePath)
            ->andReturn($payload);
        $featureExtractionService->shouldReceive('cleanupPayload')
            ->once()
            ->with($payload);
        $this->app->instance(VideoFeatureExtractionService::class, $featureExtractionService);

        $duplicateDetectionService = Mockery::mock(VideoDuplicateDetectionService::class);
        $duplicateDetectionService->shouldReceive('analyzeDatabaseMatch')
            ->once()
            ->with($payload, 80, 2, 3, 15, 250)
            ->andReturn($databaseAnalysis);
        $duplicateDetectionService->shouldReceive('analyzeReferenceSnapshotsMatch')
            ->once()
            ->with(
                $payload,
                Mockery::on(fn (array $snapshots): bool => count($snapshots) === 1 && $snapshots[0]['absolute_path'] === $referenceMatchPath),
                80,
                2,
                3,
                15,
                250
            )
            ->andReturn($referenceAnalysis);
        $this->app->instance(VideoDuplicateDetectionService::class, $duplicateDetectionService);

        $referenceVideoFeatureIndexService = Mockery::mock(ReferenceVideoFeatureIndexService::class);
        $referenceVideoFeatureIndexService->shouldReceive('syncDirectory')
            ->once()
            ->with($referenceDir)
            ->andReturn([
                'directory_path' => $referenceDir,
                'index_path' => $referenceDir . DIRECTORY_SEPARATOR . 'video-feature-index.json',
                'snapshots' => [
                    ['absolute_path' => $referenceMatchPath],
                ],
                'total_files' => 1,
                'reused_count' => 1,
                'extracted_count' => 0,
                'removed_count' => 0,
                'failed_count' => 0,
                'failed_files' => [],
            ]);
        $referenceVideoFeatureIndexService->shouldReceive('buildComparisonSnapshots')
            ->once()
            ->with(
                Mockery::on(fn (array $snapshots): bool => count($snapshots) === 1 && $snapshots[0]['absolute_path'] === $referenceMatchPath),
                $sourcePath
            )
            ->andReturn([
                ['absolute_path' => $referenceMatchPath],
            ]);
        $this->app->instance(ReferenceVideoFeatureIndexService::class, $referenceVideoFeatureIndexService);

        $expectedDuplicateDir = $this->tempDir . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $capturedDuplicatePath = null;

        $externalVideoDuplicateService = Mockery::mock(ExternalVideoDuplicateService::class);
        $externalVideoDuplicateService->shouldReceive('persistComparisonLog')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                ?array $passedAnalysis,
                string $passedSourcePath,
                array $options
            ) use ($payload, $sourcePath, $expectedDuplicateDir, &$capturedDuplicatePath): bool {
                $capturedDuplicatePath = $options['duplicate_file_path'] ?? null;

                return $passedPayload === $payload
                    && is_array($passedAnalysis)
                    && $passedSourcePath === $sourcePath
                    && is_string($capturedDuplicatePath)
                    && str_starts_with($capturedDuplicatePath, $expectedDuplicateDir)
                    && ($options['operation_status'] ?? null) === 'reference_index_match_moved'
                    && ($options['is_duplicate_detected'] ?? null) === true;
            });
        $this->app->instance(ExternalVideoDuplicateService::class, $externalVideoDuplicateService);

        $this->artisan('video:move-duplicates', [
            'path' => $this->tempDir,
            '--recursive' => 0,
            '--reference-dir' => $referenceDir,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($sourcePath);
        $this->assertIsString($capturedDuplicatePath);
        $this->assertFileExists($capturedDuplicatePath);
    }

    public function test_command_skips_reference_index_when_scan_path_equals_reference_dir(): void
    {
        $sourcePath = $this->tempDir . DIRECTORY_SEPARATOR . 'incoming.mp4';
        file_put_contents($sourcePath, 'incoming-video');

        $payload = [
            'absolute_path' => $sourcePath,
            'video_name' => 'incoming.mp4',
            'file_name' => 'incoming.mp4',
            'file_size_bytes' => 1000,
            'duration_seconds' => 13.1,
            'file_created_at' => now(),
            'file_modified_at' => now(),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 10.0,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('a', 40),
            ]],
        ];

        $databaseAnalysis = [
            'best_result' => null,
            'duplicate_match' => null,
            'candidate_count' => 0,
            'payload_frame_count' => 1,
            'requested_min_match' => 1,
        ];

        $featureExtractionService = Mockery::mock(VideoFeatureExtractionService::class);
        $featureExtractionService->shouldReceive('inspectFile')
            ->once()
            ->with($sourcePath)
            ->andReturn($payload);
        $featureExtractionService->shouldReceive('cleanupPayload')
            ->once()
            ->with($payload);
        $this->app->instance(VideoFeatureExtractionService::class, $featureExtractionService);

        $duplicateDetectionService = Mockery::mock(VideoDuplicateDetectionService::class);
        $duplicateDetectionService->shouldReceive('analyzeDatabaseMatch')
            ->once()
            ->with($payload, 80, 2, 3, 15, 250)
            ->andReturn($databaseAnalysis);
        $duplicateDetectionService->shouldReceive('analyzeReferenceSnapshotsMatch')
            ->never();
        $this->app->instance(VideoDuplicateDetectionService::class, $duplicateDetectionService);

        $referenceVideoFeatureIndexService = Mockery::mock(ReferenceVideoFeatureIndexService::class);
        $referenceVideoFeatureIndexService->shouldReceive('syncDirectory')
            ->once()
            ->with($this->tempDir)
            ->andReturn([
                'directory_path' => $this->tempDir,
                'index_path' => $this->tempDir . DIRECTORY_SEPARATOR . 'video-feature-index.json',
                'snapshots' => [
                    ['absolute_path' => $this->tempDir . DIRECTORY_SEPARATOR . 'another.mp4'],
                ],
                'total_files' => 1,
                'reused_count' => 1,
                'extracted_count' => 0,
                'removed_count' => 0,
                'failed_count' => 0,
                'failed_files' => [],
            ]);
        $referenceVideoFeatureIndexService->shouldReceive('buildComparisonSnapshots')
            ->never();
        $this->app->instance(ReferenceVideoFeatureIndexService::class, $referenceVideoFeatureIndexService);

        $externalVideoDuplicateService = Mockery::mock(ExternalVideoDuplicateService::class);
        $externalVideoDuplicateService->shouldReceive('persistComparisonLog')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                ?array $passedAnalysis,
                string $passedSourcePath,
                array $options
            ) use ($payload, $sourcePath): bool {
                return $passedPayload === $payload
                    && is_array($passedAnalysis)
                    && $passedSourcePath === $sourcePath
                    && ($options['operation_status'] ?? null) === 'no_match'
                    && ($options['operation_message'] ?? null) === '未命中 DB 重複門檻，保留原位。'
                    && ($options['is_duplicate_detected'] ?? null) === false;
            });
        $this->app->instance(ExternalVideoDuplicateService::class, $externalVideoDuplicateService);

        $this->artisan('video:move-duplicates', [
            'path' => $this->tempDir,
            '--recursive' => 0,
            '--reference-dir' => $this->tempDir,
        ])->assertExitCode(0);

        $this->assertFileExists($sourcePath);
        $this->assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . '疑似重複檔案');
    }
}

<?php

namespace Tests\Feature;

use App\Models\ExternalVideoDuplicateLog;
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

    public function test_manual_feature_mode_deletes_duplicate_and_persists_log(): void
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

        $capturedDeletedPath = null;

        $externalVideoDuplicateService = Mockery::mock(ExternalVideoDuplicateService::class);
        $externalVideoDuplicateService->shouldReceive('persistMatchResult')->never();
        $externalVideoDuplicateService->shouldReceive('persistComparisonLog')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                ?array $passedAnalysis,
                string $passedSourcePath,
                array $options
            ) use ($payload, $sourcePath, &$capturedDeletedPath): bool {
                $capturedDeletedPath = $options['duplicate_file_path'] ?? null;

                return $passedPayload === $payload
                    && is_array($passedAnalysis)
                    && $passedSourcePath === $sourcePath
                    && $capturedDeletedPath === $sourcePath
                    && ($options['operation_status'] ?? null) === 'match_deleted'
                    && ($options['is_duplicate_detected'] ?? null) === true;
            })
            ->andReturn((function (): ExternalVideoDuplicateLog {
                $record = new ExternalVideoDuplicateLog();
                $record->id = 456;
                return $record;
            })());
        $this->app->instance(ExternalVideoDuplicateService::class, $externalVideoDuplicateService);

        $this->artisan('video:move-duplicates', [
            'path' => $this->tempDir,
            '--video-feature-id' => $feature->id,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($sourcePath);
        $this->assertSame($sourcePath, $capturedDeletedPath);
    }

    public function test_command_deletes_file_when_reference_index_match_exists_after_db_miss(): void
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
        $preparedSnapshotIndex = [
            'snapshots' => [
                ['absolute_path' => $referenceMatchPath],
            ],
        ];
        $duplicateDetectionService->shouldReceive('prepareReferenceSnapshotIndex')
            ->once()
            ->with(
                Mockery::on(fn (array $snapshots): bool => count($snapshots) === 1 && $snapshots[0]['absolute_path'] === $referenceMatchPath)
            )
            ->andReturn($preparedSnapshotIndex);
        $duplicateDetectionService->shouldReceive('analyzePreparedReferenceSnapshotsMatch')
            ->once()
            ->with($payload, $preparedSnapshotIndex, 80, 2, 3, 15, 250)
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
        $referenceVideoFeatureIndexService->shouldReceive('upsertPayloadSnapshot')->never();
        $this->app->instance(ReferenceVideoFeatureIndexService::class, $referenceVideoFeatureIndexService);

        $capturedDeletedPath = null;

        $externalVideoDuplicateService = Mockery::mock(ExternalVideoDuplicateService::class);
        $externalVideoDuplicateService->shouldReceive('persistComparisonLog')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                ?array $passedAnalysis,
                string $passedSourcePath,
                array $options
            ) use ($payload, $sourcePath, &$capturedDeletedPath): bool {
                $capturedDeletedPath = $options['duplicate_file_path'] ?? null;

                return $passedPayload === $payload
                    && is_array($passedAnalysis)
                    && $passedSourcePath === $sourcePath
                    && $capturedDeletedPath === $sourcePath
                    && ($options['operation_status'] ?? null) === 'reference_index_match_deleted'
                    && ($options['is_duplicate_detected'] ?? null) === true;
            });
        $this->app->instance(ExternalVideoDuplicateService::class, $externalVideoDuplicateService);

        $this->artisan('video:move-duplicates', [
            'path' => $this->tempDir,
            '--recursive' => 0,
            '--reference-dir' => $referenceDir,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($sourcePath);
        $this->assertSame($sourcePath, $capturedDeletedPath);
    }

    public function test_command_moves_non_duplicate_file_to_reference_dir_and_updates_index(): void
    {
        $sourcePath = $this->tempDir . DIRECTORY_SEPARATOR . 'incoming.mp4';
        $referenceDir = $this->tempDir . DIRECTORY_SEPARATOR . 'reference';
        $referenceExistingPath = $referenceDir . DIRECTORY_SEPARATOR . 'existing.mp4';

        File::ensureDirectoryExists($referenceDir);
        file_put_contents($sourcePath, 'incoming-video');
        file_put_contents($referenceExistingPath, 'reference-video');

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
            'best_result' => null,
            'duplicate_match' => null,
            'candidate_count' => 1,
            'payload_frame_count' => 1,
            'requested_min_match' => 1,
        ];
        $expectedReferencePath = $referenceDir . DIRECTORY_SEPARATOR . 'incoming.mp4';

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
        $preparedSnapshotIndex = [
            'snapshots' => [
                ['absolute_path' => $referenceExistingPath],
            ],
        ];
        $duplicateDetectionService->shouldReceive('prepareReferenceSnapshotIndex')
            ->once()
            ->with(
                Mockery::on(fn (array $snapshots): bool => count($snapshots) === 1 && $snapshots[0]['absolute_path'] === $referenceExistingPath)
            )
            ->andReturn($preparedSnapshotIndex);
        $duplicateDetectionService->shouldReceive('analyzePreparedReferenceSnapshotsMatch')
            ->once()
            ->with($payload, $preparedSnapshotIndex, 80, 2, 3, 15, 250)
            ->andReturn($referenceAnalysis);
        $duplicateDetectionService->shouldReceive('appendPreparedReferenceSnapshot')
            ->once()
            ->withArgs(function (array $passedPreparedIndex, array $passedSnapshot) use ($preparedSnapshotIndex, $expectedReferencePath): bool {
                return $passedPreparedIndex === $preparedSnapshotIndex
                    && ($passedSnapshot['absolute_path'] ?? null) === $expectedReferencePath
                    && ($passedSnapshot['file_name'] ?? null) === 'incoming.mp4';
            })
            ->andReturn([
                'snapshots' => [
                    ['absolute_path' => $referenceExistingPath],
                    ['absolute_path' => $expectedReferencePath],
                ],
            ]);
        $this->app->instance(VideoDuplicateDetectionService::class, $duplicateDetectionService);

        $referenceVideoFeatureIndexService = Mockery::mock(ReferenceVideoFeatureIndexService::class);
        $referenceVideoFeatureIndexService->shouldReceive('syncDirectory')
            ->once()
            ->with($referenceDir)
            ->andReturn([
                'directory_path' => $referenceDir,
                'index_path' => $referenceDir . DIRECTORY_SEPARATOR . 'video-feature-index.json',
                'snapshots' => [
                    ['absolute_path' => $referenceExistingPath],
                ],
                'total_files' => 1,
                'reused_count' => 1,
                'extracted_count' => 0,
                'removed_count' => 0,
                'failed_count' => 0,
                'failed_files' => [],
            ]);
        $referenceVideoFeatureIndexService->shouldReceive('upsertPayloadSnapshot')
            ->once()
            ->withArgs(function (
                string $passedDirectoryPath,
                array $passedSnapshots,
                array $passedPayload,
                array $passedFailedFiles
            ) use ($referenceDir, $referenceExistingPath, $expectedReferencePath): bool {
                return $passedDirectoryPath === $referenceDir
                    && count($passedSnapshots) === 1
                    && $passedSnapshots[0]['absolute_path'] === $referenceExistingPath
                    && ($passedPayload['absolute_path'] ?? null) === $expectedReferencePath
                    && ($passedPayload['file_name'] ?? null) === 'incoming.mp4'
                    && $passedFailedFiles === [];
            })
            ->andReturn([
                'directory_path' => $referenceDir,
                'index_path' => $referenceDir . DIRECTORY_SEPARATOR . 'video-feature-index.json',
                'snapshots' => [
                    ['absolute_path' => $referenceExistingPath],
                    ['absolute_path' => $expectedReferencePath],
                ],
                'total_files' => 2,
                'failed_count' => 0,
                'failed_files' => [],
            ]);
        $this->app->instance(ReferenceVideoFeatureIndexService::class, $referenceVideoFeatureIndexService);

        $capturedReferencePath = null;

        $externalVideoDuplicateService = Mockery::mock(ExternalVideoDuplicateService::class);
        $externalVideoDuplicateService->shouldReceive('persistComparisonLog')
            ->once()
            ->withArgs(function (
                array $passedPayload,
                ?array $passedAnalysis,
                string $passedSourcePath,
                array $options
            ) use ($payload, $sourcePath, $expectedReferencePath, &$capturedReferencePath): bool {
                $capturedReferencePath = $options['duplicate_file_path'] ?? null;

                return $passedPayload === $payload
                    && is_array($passedAnalysis)
                    && $passedSourcePath === $sourcePath
                    && $capturedReferencePath === $expectedReferencePath
                    && ($options['operation_status'] ?? null) === 'moved_to_reference_dir'
                    && ($options['operation_message'] ?? null) === '未命中 DB 或暫存參考索引重複門檻，已搬移到暫存參考資料夾並更新 JSON。'
                    && ($options['is_duplicate_detected'] ?? null) === false;
            });
        $this->app->instance(ExternalVideoDuplicateService::class, $externalVideoDuplicateService);

        $this->artisan('video:move-duplicates', [
            'path' => $this->tempDir,
            '--recursive' => 0,
            '--reference-dir' => $referenceDir,
        ])->assertExitCode(0);

        $this->assertFileDoesNotExist($sourcePath);
        $this->assertSame($expectedReferencePath, $capturedReferencePath);
        $this->assertFileExists($expectedReferencePath);
        $this->assertDirectoryDoesNotExist($this->tempDir . DIRECTORY_SEPARATOR . '疑似重複檔案');
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
        $duplicateDetectionService->shouldReceive('prepareReferenceSnapshotIndex')
            ->never();
        $duplicateDetectionService->shouldReceive('analyzePreparedReferenceSnapshotsMatch')
            ->never();
        $duplicateDetectionService->shouldReceive('appendPreparedReferenceSnapshot')
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
        $referenceVideoFeatureIndexService->shouldReceive('upsertPayloadSnapshot')
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

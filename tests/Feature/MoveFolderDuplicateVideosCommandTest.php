<?php

namespace Tests\Feature;

use App\Models\FolderVideoDuplicateBatch;
use App\Models\FolderVideoDuplicateFeature;
use App\Models\FolderVideoDuplicateMatch;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MoveFolderDuplicateVideosCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('folder_video_duplicate_matches');
        Schema::dropIfExists('folder_video_duplicate_frames');
        Schema::dropIfExists('folder_video_duplicate_features');
        Schema::dropIfExists('folder_video_duplicate_batches');

        Schema::create('folder_video_duplicate_batches', function (Blueprint $table): void {
            $table->id();
            $table->string('scan_root_path', 500);
            $table->string('duplicate_directory_path', 500);
            $table->boolean('is_recursive')->default(true);
            $table->unsignedTinyInteger('threshold_percent')->default(80);
            $table->unsignedTinyInteger('min_match_required')->default(2);
            $table->unsignedInteger('window_seconds')->default(3);
            $table->unsignedInteger('max_candidates')->default(250);
            $table->unsignedInteger('limit_count')->nullable();
            $table->boolean('is_dry_run')->default(false);
            $table->boolean('cleanup_requested')->default(true);
            $table->string('status', 32)->default('running');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('processed_files')->default(0);
            $table->unsignedInteger('kept_files')->default(0);
            $table->unsignedInteger('moved_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('folder_video_duplicate_features', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('folder_video_duplicate_batch_id');
            $table->string('absolute_path', 500);
            $table->char('path_sha1', 40);
            $table->string('directory_path', 500)->nullable();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('duration_seconds', 10, 3)->default(0);
            $table->dateTime('file_created_at')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->unsignedTinyInteger('screenshot_count')->default(0);
            $table->string('feature_version', 32)->default('v1');
            $table->string('capture_rule', 64)->default('10s_x4');
            $table->boolean('is_canonical')->default(true);
            $table->string('moved_to_duplicate_path', 500)->nullable();
            $table->string('extraction_status', 32)->default('ready');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('folder_video_duplicate_frames', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('folder_video_duplicate_feature_id');
            $table->unsignedTinyInteger('capture_order');
            $table->decimal('capture_second', 10, 3)->default(0);
            $table->char('dhash_hex', 16);
            $table->char('dhash_prefix', 2);
            $table->char('frame_sha1', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->timestamps();
        });

        Schema::create('folder_video_duplicate_matches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('folder_video_duplicate_batch_id');
            $table->unsignedBigInteger('kept_feature_id');
            $table->unsignedBigInteger('duplicate_feature_id');
            $table->string('kept_file_path', 500);
            $table->string('duplicate_file_path', 500);
            $table->char('duplicate_path_sha1', 40);
            $table->string('moved_to_path', 500)->nullable();
            $table->decimal('similarity_percent', 5, 2)->default(0);
            $table->unsignedTinyInteger('matched_frames')->default(0);
            $table->unsignedTinyInteger('compared_frames')->default(0);
            $table->unsignedTinyInteger('required_matches')->default(0);
            $table->decimal('duration_delta_seconds', 10, 3)->nullable();
            $table->bigInteger('file_size_delta_bytes')->nullable();
            $table->longText('frame_comparisons_json')->nullable();
            $table->string('operation_status', 32)->default('match_moved');
            $table->text('operation_message')->nullable();
            $table->timestamps();
        });

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_move_folder_duplicate_' . uniqid('', true);
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_command_moves_later_duplicate_and_keeps_scan_rows_when_cleanup_disabled(): void
    {
        $alphaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha.mp4';
        $betaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'beta.mp4';
        $gammaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'gamma.mp4';

        file_put_contents($alphaPath, 'alpha');
        file_put_contents($betaPath, 'beta');
        file_put_contents($gammaPath, 'gamma');

        touch($alphaPath, time() - 30);
        touch($betaPath, time() - 20);
        touch($gammaPath, time() - 10);

        $alphaPayload = $this->buildPayload($alphaPath, [
            ['capture_order' => 1, 'capture_second' => 10.0, 'dhash_hex' => '0011223344556677', 'dhash_prefix' => '00', 'frame_sha1' => str_repeat('a', 40)],
            ['capture_order' => 2, 'capture_second' => 20.0, 'dhash_hex' => '8899aabbccddeeff', 'dhash_prefix' => '88', 'frame_sha1' => str_repeat('b', 40)],
        ], 13.1, 1000);
        $betaPayload = $this->buildPayload($betaPath, [
            ['capture_order' => 1, 'capture_second' => 10.0, 'dhash_hex' => '0011223344556677', 'dhash_prefix' => '00', 'frame_sha1' => str_repeat('c', 40)],
            ['capture_order' => 2, 'capture_second' => 20.0, 'dhash_hex' => '8899aabbccddeeff', 'dhash_prefix' => '88', 'frame_sha1' => str_repeat('d', 40)],
        ], 13.08, 1010);
        $gammaPayload = $this->buildPayload($gammaPath, [
            ['capture_order' => 1, 'capture_second' => 10.0, 'dhash_hex' => 'ffeeddccbbaa9988', 'dhash_prefix' => 'ff', 'frame_sha1' => str_repeat('e', 40)],
            ['capture_order' => 2, 'capture_second' => 20.0, 'dhash_hex' => '7766554433221100', 'dhash_prefix' => '77', 'frame_sha1' => str_repeat('f', 40)],
        ], 20.0, 2000);

        $service = Mockery::mock(VideoFeatureExtractionService::class);
        $service->shouldReceive('inspectFile')->once()->with($alphaPath)->andReturn($alphaPayload);
        $service->shouldReceive('inspectFile')->once()->with($betaPath)->andReturn($betaPayload);
        $service->shouldReceive('inspectFile')->once()->with($gammaPath)->andReturn($gammaPayload);
        $service->shouldReceive('cleanupPayload')->times(3);
        $service->shouldReceive('isValidDhash')->andReturnUsing(
            fn (string $hex): bool => (bool) preg_match('/^[0-9a-f]{16}$/', strtolower(trim($hex)))
        );
        $service->shouldReceive('hashSimilarityPercent')->andReturnUsing(function (string $left, string $right): int {
            return strtolower($left) === strtolower($right) ? 100 : 0;
        });
        $this->app->instance(VideoFeatureExtractionService::class, $service);

        $this->artisan('video:move-folder-duplicates', [
            'path' => $this->tempDir,
            '--cleanup-db' => 0,
        ])->assertExitCode(0);

        $duplicateDir = $this->tempDir . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $movedBetaPath = $duplicateDir . DIRECTORY_SEPARATOR . 'beta.mp4';

        $this->assertFileExists($alphaPath);
        $this->assertFileDoesNotExist($betaPath);
        $this->assertFileExists($movedBetaPath);
        $this->assertFileExists($gammaPath);

        $batch = FolderVideoDuplicateBatch::query()->first();
        $this->assertNotNull($batch);
        $this->assertSame(3, $batch->processed_files);
        $this->assertSame(2, $batch->kept_files);
        $this->assertSame(1, $batch->moved_files);
        $this->assertSame(0, $batch->failed_files);

        $this->assertSame(3, FolderVideoDuplicateFeature::query()->count());
        $this->assertSame(2, FolderVideoDuplicateFeature::query()->where('is_canonical', true)->count());

        $match = FolderVideoDuplicateMatch::query()->first();
        $this->assertNotNull($match);
        $this->assertSame('match_moved', $match->operation_status);
        $this->assertSame($alphaPath, $match->kept_file_path);
        $this->assertSame($betaPath, $match->duplicate_file_path);
        $this->assertSame($movedBetaPath, $match->moved_to_path);
        $this->assertSame(100.0, (float) $match->similarity_percent);
        $this->assertCount(2, (array) $match->frame_comparisons_json);
    }

    public function test_command_cleans_up_batch_rows_by_default(): void
    {
        $alphaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha.mp4';
        $betaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'beta.mp4';

        file_put_contents($alphaPath, 'alpha');
        file_put_contents($betaPath, 'beta');

        touch($alphaPath, time() - 20);
        touch($betaPath, time() - 10);

        $alphaPayload = $this->buildPayload($alphaPath, [
            ['capture_order' => 1, 'capture_second' => 10.0, 'dhash_hex' => '0011223344556677', 'dhash_prefix' => '00', 'frame_sha1' => str_repeat('a', 40)],
        ], 8.0, 1000);
        $betaPayload = $this->buildPayload($betaPath, [
            ['capture_order' => 1, 'capture_second' => 10.0, 'dhash_hex' => '0011223344556677', 'dhash_prefix' => '00', 'frame_sha1' => str_repeat('b', 40)],
        ], 8.0, 1010);

        $service = Mockery::mock(VideoFeatureExtractionService::class);
        $service->shouldReceive('inspectFile')->once()->with($alphaPath)->andReturn($alphaPayload);
        $service->shouldReceive('inspectFile')->once()->with($betaPath)->andReturn($betaPayload);
        $service->shouldReceive('cleanupPayload')->times(2);
        $service->shouldReceive('isValidDhash')->andReturnTrue();
        $service->shouldReceive('hashSimilarityPercent')->andReturn(100);
        $this->app->instance(VideoFeatureExtractionService::class, $service);

        $this->artisan('video:move-folder-duplicates', [
            'path' => $this->tempDir,
        ])->assertExitCode(0);

        $this->assertSame(0, FolderVideoDuplicateBatch::query()->count());
        $this->assertSame(0, FolderVideoDuplicateFeature::query()->count());
        $this->assertSame(0, FolderVideoDuplicateMatch::query()->count());
        $this->assertFileExists($this->tempDir . DIRECTORY_SEPARATOR . '疑似重複檔案' . DIRECTORY_SEPARATOR . 'beta.mp4');
    }

    private function buildPayload(string $path, array $frames, float $durationSeconds, int $fileSizeBytes): array
    {
        return [
            'absolute_path' => $path,
            'video_name' => basename($path),
            'file_name' => basename($path),
            'file_size_bytes' => $fileSizeBytes,
            'duration_seconds' => $durationSeconds,
            'file_created_at' => now(),
            'file_modified_at' => now(),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'frames' => array_map(function (array $frame): array {
                return $frame + [
                    'image_width' => 1280,
                    'image_height' => 720,
                ];
            }, $frames),
        ];
    }
}

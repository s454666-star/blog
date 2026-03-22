<?php

namespace Tests\Unit;

use App\Models\FolderVideoDuplicateBatch;
use App\Models\FolderVideoDuplicateFeature;
use App\Models\FolderVideoDuplicateFrame;
use App\Services\FolderVideoDuplicateService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class FolderVideoDuplicateServiceTest extends TestCase
{
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
    }

    public function test_single_frame_match_can_fall_back_to_duration_when_prefix_differs(): void
    {
        $batch = FolderVideoDuplicateBatch::query()->create([
            'scan_root_path' => 'C:\\scan',
            'duplicate_directory_path' => 'C:\\scan\\疑似重複檔案',
            'started_at' => now(),
        ]);

        $feature = FolderVideoDuplicateFeature::query()->create([
            'folder_video_duplicate_batch_id' => $batch->id,
            'absolute_path' => 'C:\\scan\\kept.mp4',
            'path_sha1' => sha1('kept'),
            'directory_path' => 'C:\\scan',
            'file_name' => 'kept.mp4',
            'file_size_bytes' => 5000,
            'duration_seconds' => 10.050,
            'screenshot_count' => 1,
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'is_canonical' => true,
        ]);

        FolderVideoDuplicateFrame::query()->create([
            'folder_video_duplicate_feature_id' => $feature->id,
            'capture_order' => 1,
            'capture_second' => 9.800,
            'dhash_hex' => '1f0d1814d4cc7bd3',
            'dhash_prefix' => '1f',
            'frame_sha1' => str_repeat('1', 40),
        ]);

        $extractionService = Mockery::mock(VideoFeatureExtractionService::class);
        $extractionService->shouldReceive('isValidDhash')->andReturnUsing(
            fn (string $hex): bool => (bool) preg_match('/^[0-9a-f]{16}$/', strtolower(trim($hex)))
        );
        $extractionService->shouldReceive('hashSimilarityPercent')->andReturn(85);

        $service = new FolderVideoDuplicateService($extractionService);
        $analysis = $service->analyzeBatchMatch($batch, [
            'duration_seconds' => 10.077,
            'file_size_bytes' => 1000,
            'frames' => [[
                'capture_order' => 1,
                'capture_second' => 9.827,
                'dhash_hex' => '0f181cd494cc7b53',
                'dhash_prefix' => '0f',
                'frame_sha1' => str_repeat('2', 40),
            ]],
        ], 80, 2, 3, 250);

        $this->assertSame(1, $analysis['candidate_count']);
        $this->assertNotNull($analysis['duplicate_match']);
        $this->assertSame($feature->id, $analysis['duplicate_match']['feature']->id);
        $this->assertTrue($analysis['duplicate_match']['passes_threshold']);
        $this->assertSame(1, $analysis['duplicate_match']['required_matches']);
        $this->assertSame(85.0, $analysis['duplicate_match']['similarity_percent']);
    }
}

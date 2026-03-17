<?php

namespace Tests\Feature;

use App\Models\ExternalVideoDuplicateMatch;
use App\Services\ExternalVideoDuplicateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalVideoDuplicateServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::statement('PRAGMA foreign_keys = ON');

        Schema::create('external_video_duplicate_matches', function (Blueprint $table): void {
            $table->id();
            $table->string('duplicate_file_path', 500);
            $table->timestamps();
        });

        Schema::create('external_video_duplicate_frames', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('external_video_duplicate_match_id');
            $table->unsignedTinyInteger('capture_order');
            $table->string('screenshot_path', 500)->nullable();
            $table->timestamps();

            $table->foreign('external_video_duplicate_match_id')
                ->references('id')
                ->on('external_video_duplicate_matches')
                ->cascadeOnDelete();
        });

        Schema::create('external_video_duplicate_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('external_video_duplicate_match_id')->nullable();
            $table->string('source_file_path', 500);
            $table->string('file_name', 255);
            $table->timestamps();

            $table->foreign('external_video_duplicate_match_id')
                ->references('id')
                ->on('external_video_duplicate_matches')
                ->nullOnDelete();
        });

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_external_duplicate_service_' . uniqid('', true);
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('external_video_duplicate_logs');
        Schema::dropIfExists('external_video_duplicate_frames');
        Schema::dropIfExists('external_video_duplicate_matches');

        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_delete_record_removes_linked_logs_and_keeps_unrelated_logs(): void
    {
        $duplicateFilePath = $this->tempDir . DIRECTORY_SEPARATOR . 'duplicate.mp4';
        file_put_contents($duplicateFilePath, 'duplicate-video');

        DB::table('external_video_duplicate_matches')->insert([
            'id' => 100,
            'duplicate_file_path' => $duplicateFilePath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('external_video_duplicate_matches')->insert([
            'id' => 200,
            'duplicate_file_path' => $this->tempDir . DIRECTORY_SEPARATOR . 'other.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('external_video_duplicate_frames')->insert([
            'external_video_duplicate_match_id' => 100,
            'capture_order' => 1,
            'screenshot_path' => 'duplicates/test.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('external_video_duplicate_logs')->insert([
            'id' => 1,
            'external_video_duplicate_match_id' => 100,
            'source_file_path' => 'C:\\source\\duplicate.mp4',
            'file_name' => 'duplicate.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('external_video_duplicate_logs')->insert([
            'id' => 2,
            'external_video_duplicate_match_id' => 200,
            'source_file_path' => 'C:\\source\\other.mp4',
            'file_name' => 'other.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ExternalVideoDuplicateService::class);
        $record = ExternalVideoDuplicateMatch::query()->findOrFail(100);

        $result = $service->deleteRecord($record);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['file_deleted']);
        $this->assertFileDoesNotExist($duplicateFilePath);
        $this->assertDatabaseMissing('external_video_duplicate_matches', ['id' => 100]);
        $this->assertDatabaseMissing('external_video_duplicate_logs', ['id' => 1]);
        $this->assertDatabaseHas('external_video_duplicate_logs', ['id' => 2]);
    }

    public function test_batch_dismiss_removes_match_and_logs_but_keeps_file(): void
    {
        $duplicateFilePath = $this->tempDir . DIRECTORY_SEPARATOR . 'keep.mp4';
        file_put_contents($duplicateFilePath, 'keep-video');

        DB::table('external_video_duplicate_matches')->insert([
            'id' => 300,
            'duplicate_file_path' => $duplicateFilePath,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('external_video_duplicate_logs')->insert([
            'id' => 3,
            'external_video_duplicate_match_id' => 300,
            'source_file_path' => 'C:\\source\\keep.mp4',
            'file_name' => 'keep.mp4',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->postJson(route('videos.external-duplicates.batch-dismiss'), [
            'ids' => [300],
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'dismissed_ids' => [300],
            ]);

        $this->assertFileExists($duplicateFilePath);
        $this->assertDatabaseMissing('external_video_duplicate_matches', ['id' => 300]);
        $this->assertDatabaseMissing('external_video_duplicate_logs', ['id' => 3]);
    }
}

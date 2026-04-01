<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CleanupStaleFilestoreSessionsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('telegram_filestore_restore_files');
        Schema::dropIfExists('telegram_filestore_restore_sessions');
        Schema::dropIfExists('telegram_filestore_bridge_contexts');
        Schema::dropIfExists('telegram_filestore_files');
        Schema::dropIfExists('telegram_filestore_sessions');

        Schema::create('telegram_filestore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('username')->nullable();
            $table->string('encrypt_token')->nullable();
            $table->string('public_token')->nullable();
            $table->string('source_token')->nullable();
            $table->string('status')->default('closed');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedBigInteger('total_size')->default(0);
            $table->unsignedInteger('share_count')->default(0);
            $table->dateTime('last_shared_at')->nullable();
            $table->dateTime('close_upload_prompted_at')->nullable();
            $table->unsignedTinyInteger('is_sending')->default(0);
            $table->dateTime('sending_started_at')->nullable();
            $table->dateTime('sending_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('closed_at')->nullable();
        });

        Schema::create('telegram_filestore_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('file_id');
            $table->string('file_unique_id');
            $table->string('source_token')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type')->default('document');
            $table->text('raw_payload')->nullable();
            $table->dateTime('created_at')->nullable();
        });

        Schema::create('telegram_filestore_bridge_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('context_type', 32);
            $table->char('context_hash', 64);
            $table->string('context_value')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();
            $table->unique(['context_type', 'context_hash']);
        });

        Schema::create('telegram_filestore_restore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_session_id')->nullable();
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->string('source_token')->nullable();
            $table->string('source_public_token')->nullable();
            $table->string('target_bot_username');
            $table->unsignedBigInteger('target_chat_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('processed_files')->default(0);
            $table->unsignedInteger('success_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->unsignedBigInteger('last_source_file_id')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('telegram_filestore_restore_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restore_session_id');
            $table->unsignedBigInteger('source_session_id')->nullable();
            $table->unsignedBigInteger('source_file_row_id');
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->string('source_file_id')->nullable();
            $table->string('source_file_unique_id')->nullable();
            $table->string('source_token')->nullable();
            $table->string('source_public_token')->nullable();
            $table->unsignedBigInteger('forwarded_message_id')->nullable();
            $table->unsignedBigInteger('target_chat_id')->nullable();
            $table->unsignedBigInteger('target_message_id')->nullable();
            $table->string('target_file_id')->nullable();
            $table->string('target_file_unique_id')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type', 32)->default('document');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->text('raw_payload')->nullable();
            $table->dateTime('forwarded_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_cleanup_command_removes_stale_uploading_sessions_and_finalizes_stale_restore_sessions(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            [
                'id' => 10,
                'chat_id' => 111,
                'status' => 'uploading',
                'total_files' => 1,
                'total_size' => 10,
                'created_at' => now()->subDays(2),
            ],
            [
                'id' => 11,
                'chat_id' => 111,
                'status' => 'uploading',
                'total_files' => 1,
                'total_size' => 20,
                'created_at' => now()->subDays(2),
            ],
            [
                'id' => 12,
                'chat_id' => 222,
                'status' => 'uploading',
                'total_files' => 0,
                'total_size' => 0,
                'created_at' => now()->subHours(3),
            ],
        ]);

        DB::table('telegram_filestore_files')->insert([
            [
                'id' => 100,
                'session_id' => 10,
                'chat_id' => 111,
                'message_id' => 1000,
                'file_id' => 'old-file',
                'file_unique_id' => 'old-uniq',
                'file_name' => 'old.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 10,
                'file_type' => 'document',
                'raw_payload' => '{}',
                'created_at' => now()->subDays(2),
            ],
            [
                'id' => 101,
                'session_id' => 11,
                'chat_id' => 111,
                'message_id' => 1001,
                'file_id' => 'recent-file',
                'file_unique_id' => 'recent-uniq',
                'file_name' => 'recent.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 20,
                'file_type' => 'document',
                'raw_payload' => '{}',
                'created_at' => now()->subHours(2),
            ],
        ]);

        DB::table('telegram_filestore_bridge_contexts')->insert([
            'session_id' => 10,
            'context_type' => 'message_id',
            'context_hash' => hash('sha256', '1000'),
            'context_value' => '1000',
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
        ]);

        DB::table('telegram_filestore_restore_sessions')->insert([
            [
                'id' => 20,
                'source_session_id' => 10,
                'target_bot_username' => 'file_backup_restore_bot',
                'status' => 'running',
                'total_files' => 2,
                'processed_files' => 1,
                'success_files' => 1,
                'failed_files' => 0,
                'started_at' => now()->subDays(2),
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'id' => 21,
                'source_session_id' => 11,
                'target_bot_username' => 'file_backup_restore_bot',
                'status' => 'running',
                'total_files' => 1,
                'processed_files' => 0,
                'success_files' => 0,
                'failed_files' => 0,
                'started_at' => now()->subHours(1),
                'created_at' => now()->subHours(1),
                'updated_at' => now()->subHours(1),
            ],
        ]);

        DB::table('telegram_filestore_restore_files')->insert([
            [
                'id' => 200,
                'restore_session_id' => 20,
                'source_file_row_id' => 100,
                'status' => 'synced',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'id' => 201,
                'restore_session_id' => 20,
                'source_file_row_id' => 101,
                'status' => 'pending',
                'created_at' => now()->subDays(2),
                'updated_at' => now()->subDays(2),
            ],
            [
                'id' => 202,
                'restore_session_id' => 21,
                'source_file_row_id' => 102,
                'status' => 'pending',
                'created_at' => now()->subHours(1),
                'updated_at' => now()->subHours(1),
            ],
        ]);

        $this->artisan('filestore:cleanup-stale-sessions --hours=24')
            ->expectsOutputToContain('uploading_deleted_sessions=1')
            ->expectsOutputToContain('uploading_deleted_files=1')
            ->expectsOutputToContain('restore_finalized_sessions=1')
            ->expectsOutputToContain('restore_newly_failed_files=1')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('telegram_filestore_sessions', ['id' => 10]);
        $this->assertDatabaseMissing('telegram_filestore_files', ['id' => 100]);
        $this->assertDatabaseMissing('telegram_filestore_bridge_contexts', ['session_id' => 10]);

        $this->assertDatabaseHas('telegram_filestore_sessions', ['id' => 11, 'status' => 'uploading']);
        $this->assertDatabaseHas('telegram_filestore_sessions', ['id' => 12, 'status' => 'uploading']);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'id' => 20,
            'status' => 'completed_with_failures',
            'processed_files' => 2,
            'success_files' => 1,
            'failed_files' => 1,
        ]);
        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'id' => 201,
            'status' => 'failed',
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'id' => 21,
            'status' => 'running',
        ]);
        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'id' => 202,
            'status' => 'pending',
        ]);
    }
}

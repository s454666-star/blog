<?php

namespace Tests\Feature;

use App\Jobs\SendFilestoreSessionFilesJob;
use App\Jobs\TelegramFilestoreDebouncedPromptJob;
use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use App\Services\TelegramFilestoreBridgeContextService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class TelegramFilestoreBotControllerBridgeWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('telegram.filestore_bot_token', 'filestore-test-token');
        config()->set('telegram.filestore_bot_username', 'filestoebot');
        config()->set('telegram.backup_restore_bot_token', 'backup-restore-test-token');
        config()->set('telegram.backup_restore_bot_username', 'new_files_star_bot');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('telegram_filestore_restore_files');
        Schema::dropIfExists('telegram_filestore_restore_sessions');
        Schema::dropIfExists('telegram_filestore_files');
        Schema::dropIfExists('telegram_filestore_sessions');
        Schema::dropIfExists('telegram_filestore_bridge_contexts');

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

        Schema::create('telegram_filestore_restore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_session_id')->nullable();
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->string('source_token')->nullable();
            $table->string('source_public_token')->nullable();
            $table->string('target_bot_username');
            $table->unsignedBigInteger('target_chat_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('processed_files')->default(0);
            $table->unsignedInteger('success_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->unsignedBigInteger('last_source_file_id')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type')->default('document');
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->text('raw_payload')->nullable();
            $table->dateTime('forwarded_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
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
    }

    public function test_webhook_uses_pending_bridge_session_for_forwarded_file_without_scheduling_close_prompt(): void
    {
        Queue::fake();

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => null,
            'username' => null,
            'encrypt_token' => null,
            'public_token' => null,
            'source_token' => 'mtfxqbot_4V_bridge0007',
            'status' => 'uploading',
            'total_files' => 0,
            'total_size' => 0,
            'share_count' => 0,
            'created_at' => now(),
        ]);

        app(TelegramFilestoreBridgeContextService::class)->rememberPendingSession(
            (int) $session->id,
            ['bridge-uniq-0007']
        );
        Cache::flush();

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9001,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'document' => [
                    'file_id' => 'BAAC-bridge-file-id',
                    'file_unique_id' => 'bridge-uniq-0007',
                    'file_name' => 'bridge.bin',
                    'mime_type' => 'application/octet-stream',
                    'file_size' => 4096,
                ],
                'forward_from' => [
                    'id' => 8781063603,
                    'is_bot' => true,
                    'username' => 'mtfxqbot',
                ],
            ],
        ]);

        $response->assertOk();

        $session->refresh();

        $this->assertSame(8491679630, (int) $session->chat_id);
        $this->assertSame('s4546663', $session->username);
        $this->assertSame('mtfxqbot_4V_bridge0007', $session->source_token);
        $this->assertSame(1, (int) $session->total_files);
        $this->assertSame(4096, (int) $session->total_size);
        $this->assertDatabaseCount('telegram_filestore_sessions', 1);

        $file = TelegramFilestoreFile::query()->where('session_id', $session->id)->firstOrFail();
        $this->assertSame('bridge-uniq-0007', $file->file_unique_id);
        $this->assertSame('mtfxqbot_4V_bridge0007', $file->source_token);

        Queue::assertNotPushed(TelegramFilestoreDebouncedPromptJob::class);
    }

    public function test_webhook_binds_bridge_session_from_control_text_then_accepts_following_media_by_chat_id(): void
    {
        Queue::fake();

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => null,
            'encrypt_token' => null,
            'public_token' => null,
            'source_token' => 'mtfxqbot_3V_bridgechat400',
            'status' => 'uploading',
            'total_files' => 0,
            'total_size' => 0,
            'share_count' => 0,
            'created_at' => now(),
        ]);

        $controlResponse = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9100,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => 'filestorebridge|' . $session->id . '|mtfxqbot_3V_bridgechat400',
            ],
        ]);

        $controlResponse->assertOk();

        Cache::flush();

        $fileResponse = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9101,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'video' => [
                    'file_id' => 'BAAC-bridge-chat-bind-video',
                    'file_unique_id' => 'bot-api-unique-id-9101',
                    'mime_type' => 'video/mp4',
                    'file_size' => 8192,
                ],
                'forward_from' => [
                    'id' => 8781063603,
                    'is_bot' => true,
                    'username' => 'mtfxqbot',
                ],
            ],
        ]);

        $fileResponse->assertOk();

        $session->refresh();

        $this->assertSame(8491679630, (int) $session->chat_id);
        $this->assertSame('s4546663', $session->username);
        $this->assertSame(1, (int) $session->total_files);
        $this->assertSame(8192, (int) $session->total_size);

        $file = TelegramFilestoreFile::query()->where('session_id', $session->id)->firstOrFail();
        $this->assertSame(9101, (int) $file->message_id);
        $this->assertSame('bot-api-unique-id-9101', $file->file_unique_id);
        $this->assertSame('mtfxqbot_3V_bridgechat400', $file->source_token);

        Queue::assertNotPushed(TelegramFilestoreDebouncedPromptJob::class);
    }

    public function test_webhook_schedules_close_prompt_for_regular_upload_session(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9002,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'document' => [
                    'file_id' => 'BAAC-regular-file-id',
                    'file_unique_id' => 'regular-uniq-0008',
                    'file_name' => 'regular.bin',
                    'mime_type' => 'application/octet-stream',
                    'file_size' => 2048,
                ],
            ],
        ]);

        $response->assertOk();

        $session = TelegramFilestoreSession::query()->firstOrFail();

        $this->assertSame(8491679630, (int) $session->chat_id);
        $this->assertSame('s4546663', $session->username);
        $this->assertNull($session->source_token);
        $this->assertSame('uploading', $session->status);
        $this->assertSame(1, (int) $session->total_files);

        Queue::assertPushed(TelegramFilestoreDebouncedPromptJob::class);
        Queue::assertPushedTimes(TelegramFilestoreDebouncedPromptJob::class, 1);
    }

    public function test_webhook_cleans_stale_uploading_session_before_creating_new_one(): void
    {
        Queue::fake();

        $staleSession = TelegramFilestoreSession::query()->create([
            'chat_id' => 8491679630,
            'username' => 'stale-user',
            'encrypt_token' => null,
            'public_token' => null,
            'source_token' => null,
            'status' => 'uploading',
            'total_files' => 1,
            'total_size' => 1024,
            'share_count' => 0,
            'close_upload_prompted_at' => now()->subDays(2),
            'created_at' => now()->subDays(2),
        ]);

        TelegramFilestoreFile::query()->create([
            'session_id' => (int) $staleSession->id,
            'chat_id' => 8491679630,
            'message_id' => 8001,
            'file_id' => 'STALE-FILE-ID',
            'file_unique_id' => 'stale-uniq-8001',
            'source_token' => null,
            'file_name' => 'stale.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 1024,
            'file_type' => 'document',
            'raw_payload' => '{}',
            'created_at' => now()->subDays(2),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9003,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'document' => [
                    'file_id' => 'BAAC-clean-file-id',
                    'file_unique_id' => 'clean-uniq-9003',
                    'file_name' => 'fresh.bin',
                    'mime_type' => 'application/octet-stream',
                    'file_size' => 2048,
                ],
            ],
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('telegram_filestore_sessions', ['id' => (int) $staleSession->id]);
        $this->assertDatabaseMissing('telegram_filestore_files', ['file_unique_id' => 'stale-uniq-8001']);
        $this->assertDatabaseCount('telegram_filestore_sessions', 1);
        $this->assertDatabaseCount('telegram_filestore_files', 1);

        $session = TelegramFilestoreSession::query()->firstOrFail();
        $this->assertNotSame((int) $staleSession->id, (int) $session->id);
        $this->assertSame(8491679630, (int) $session->chat_id);
        $this->assertSame('uploading', (string) $session->status);
        $this->assertSame(1, (int) $session->total_files);
        $this->assertSame(2048, (int) $session->total_size);

        Queue::assertPushed(TelegramFilestoreDebouncedPromptJob::class);
    }

    public function test_webhook_uses_pending_forwarded_message_id_for_bridge_session_without_scheduling_close_prompt(): void
    {
        Queue::fake();

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => null,
            'encrypt_token' => null,
            'public_token' => null,
            'source_token' => 'mtfxqbot_2V_bridge374',
            'status' => 'uploading',
            'total_files' => 0,
            'total_size' => 0,
            'share_count' => 0,
            'created_at' => now(),
        ]);

        app(TelegramFilestoreBridgeContextService::class)->rememberPendingForwardedMessageIds(
            (int) $session->id,
            [888196]
        );
        Cache::flush();

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 888196,
                'from' => [
                    'id' => 7702694790,
                    'is_bot' => false,
                    'username' => 's4546662',
                ],
                'chat' => [
                    'id' => 7702694790,
                    'username' => 's4546662',
                    'type' => 'private',
                ],
                'video' => [
                    'file_id' => 'BAAC-forwarded-video-id',
                    'file_unique_id' => 'forwarded-bridge-uniq-888196',
                    'mime_type' => 'video/mp4',
                    'file_size' => 1200,
                ],
                'forward_from' => [
                    'id' => 8781063603,
                    'is_bot' => true,
                    'username' => 'mtfxqbot',
                ],
            ],
        ]);

        $response->assertOk();

        $session->refresh();

        $this->assertSame(7702694790, (int) $session->chat_id);
        $this->assertSame('s4546662', $session->username);
        $this->assertSame(1, (int) $session->total_files);
        $this->assertSame(1200, (int) $session->total_size);

        $file = TelegramFilestoreFile::query()->where('session_id', $session->id)->firstOrFail();
        $this->assertSame(888196, (int) $file->message_id);
        $this->assertSame('forwarded-bridge-uniq-888196', $file->file_unique_id);
        $this->assertSame('mtfxqbot_2V_bridge374', $file->source_token);

        Queue::assertNotPushed(TelegramFilestoreDebouncedPromptJob::class);
    }

    public function test_webhook_extracts_source_token_from_notification_text_and_dispatches_send_job(): void
    {
        Queue::fake();

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_2V_testreply009',
            'source_token' => 'mtfxqbot_2V_31u7U7d4v7M1M6b3C637',
            'status' => 'closed',
            'total_files' => 2,
            'total_size' => 4096,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9301,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => 'mtfxqbot_2V_31u7U7d4v7M1M6b3C637  已收錄至機器人 @filestoebot',
            ],
        ]);

        $response->assertOk();

        $session->refresh();

        $this->assertSame(1, (int) $session->is_sending);
        $this->assertSame(1, (int) $session->share_count);
        $this->assertNotNull($session->sending_started_at);

        Queue::assertPushed(SendFilestoreSessionFilesJob::class);
        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 1);
        Queue::assertNotPushed(TelegramFilestoreDebouncedPromptJob::class);
    }

    public function test_webhook_extracts_multiple_tokens_from_one_message_and_dispatches_all_send_jobs(): void
    {
        Queue::fake();

        $firstSession = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_multi001',
            'source_token' => 'mtfxqbot_1V_6107r7s4n7K2917293V4',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $secondSession = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_20P_multi002',
            'source_token' => 'mtfxqbot_20P_1V_O1b7p724e7O1V8U9F3q4',
            'status' => 'closed',
            'total_files' => 20,
            'total_size' => 8192,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9302,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => implode("\n", [
                    'mtfxqbot_1V_6107r7s4n7K2917293V4  已收錄至機器人 @filestoebot',
                    'mtfxqbot_20P_1V_O1b7p724e7O1V8U9F3q4  已收錄至機器人 @filestoebot',
                ]),
            ],
        ]);

        $response->assertOk();

        $firstSession->refresh();
        $secondSession->refresh();

        $this->assertSame(1, (int) $firstSession->is_sending);
        $this->assertSame(1, (int) $firstSession->share_count);
        $this->assertSame(1, (int) $secondSession->is_sending);
        $this->assertSame(1, (int) $secondSession->share_count);

        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 2);
        Queue::assertNotPushed(TelegramFilestoreDebouncedPromptJob::class);
    }

    public function test_webhook_releases_stale_sending_lock_before_requeueing(): void
    {
        Queue::fake();

        $staleStartedAt = now()->subHours(2)->format('Y-m-d H:i:s');

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_stale0001',
            'source_token' => 'mtfxqbot_1V_stale0001',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 1024,
            'share_count' => 2,
            'is_sending' => 1,
            'sending_started_at' => $staleStartedAt,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9401,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => 'filestoebot_1V_stale0001',
            ],
        ]);

        $response->assertOk();

        $session->refresh();

        $this->assertSame(1, (int) $session->is_sending);
        $this->assertSame(3, (int) $session->share_count);
        $this->assertNotSame($staleStartedAt, $session->sending_started_at);

        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 1);
    }

    public function test_webhook_releases_send_lock_when_dispatch_fails(): void
    {
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('queue busy'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_dispatchfail1',
            'source_token' => 'mtfxqbot_1V_dispatchfail1',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 1024,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9402,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => 'filestoebot_1V_dispatchfail1',
            ],
        ]);

        $response->assertOk();

        $session->refresh();

        $this->assertSame(0, (int) $session->is_sending);
        $this->assertSame(0, (int) $session->share_count);
        $this->assertNotNull($session->sending_finished_at);
    }

    public function test_webhook_ignores_filepan_tokens_and_only_dispatches_supported_tokens(): void
    {
        Queue::fake();

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_supported0001',
            'source_token' => 'showfilesbot_1V_supported0001',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9403,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => implode("\n", [
                    '@filepan_bot:_43D_r3f2XjEFqBy3',
                    'showfilesbot_1V_supported0001',
                ]),
            ],
        ]);

        $response->assertOk();

        $session->refresh();

        $this->assertSame(1, (int) $session->is_sending);
        $this->assertSame(1, (int) $session->share_count);

        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 1);
    }

    public function test_webhook_batches_multi_token_feedback_into_single_message(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        config()->set('telegram.filestore_bot_token', 'test-token');

        TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_batchok0001',
            'source_token' => 'showfilesbot_1V_batchok0001',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_batchok0002',
            'source_token' => 'showfilesbot_1V_batchok0002',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9404,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => implode("\n", [
                    'showfilesbot_1V_batchok0001',
                    'showfilesbot_1V_batchok0002',
                    'showfilesbot_1V_missing0003',
                ]),
            ],
        ]);

        $response->assertOk();

        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 2);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) ($request['text'] ?? '');

            return str_contains($text, '本次代碼處理結果（3 個）：')
                && str_contains($text, '已加入傳送佇列：2 個')
                && str_contains($text, '找不到檔案：1 個')
                && str_contains($text, 'showfilesbot_1V_missing0003');
        });
    }

    public function test_webhook_single_token_success_sends_queue_notice(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        config()->set('telegram.filestore_bot_token', 'test-token');

        TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_singleok0001',
            'source_token' => 'showfilesbot_1V_singleok0001',
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook', [
            'message' => [
                'message_id' => 9405,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => 'showfilesbot_1V_singleok0001',
            ],
        ]);

        $response->assertOk();

        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 1);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            if (!str_contains($request->url(), '/sendMessage')) {
                return false;
            }

            $text = (string) ($request['text'] ?? '');

            return str_contains($text, 'showfilesbot_1V_singleok0001')
                && str_contains($text, '已加入傳送佇列，開始處理中');
        });
    }

    public function test_new_files_star_webhook_decodes_alias_tokens_and_replies_via_backup_bot_token(): void
    {
        Queue::fake();
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_newroute0001',
            'source_token' => null,
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $response = $this->postJson('/api/telegram/filestore/webhook/new-files-star', [
            'message' => [
                'message_id' => 9501,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'username' => 's4546663',
                    'type' => 'private',
                ],
                'text' => implode("\n", [
                    'new_files_star_bot_1V_newroute0001',
                    'new_files_star_bot_1V_missing0002',
                ]),
            ],
        ]);

        $response->assertOk();

        Queue::assertPushed(SendFilestoreSessionFilesJob::class, function (SendFilestoreSessionFilesJob $job): bool {
            return $job->getBotProfile() === 'backup_restore';
        });
        Queue::assertPushedTimes(SendFilestoreSessionFilesJob::class, 1);

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.telegram.org/botbackup-restore-test-token/sendMessage') {
                return false;
            }

            $text = (string) ($request['text'] ?? '');

            return str_contains($text, '本次代碼處理結果（2 個）：')
                && str_contains($text, '已加入傳送佇列：1 個')
                && str_contains($text, '找不到檔案：1 個')
                && str_contains($text, 'new_files_star_bot_1V_missing0002');
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'botfilestore-test-token/');
        });
    }

    public function test_send_filestore_session_files_job_uses_backup_restore_bot_token(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $session = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => 'mtfx-user',
            'encrypt_token' => null,
            'public_token' => 'filestoebot_1V_jobbackup0001',
            'source_token' => null,
            'status' => 'closed',
            'total_files' => 1,
            'total_size' => 2048,
            'share_count' => 0,
            'is_sending' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $sourceFile = TelegramFilestoreFile::query()->create([
            'session_id' => $session->id,
            'chat_id' => 7702694790,
            'message_id' => 9601,
            'file_id' => 'BAAC-job-backup-file-id',
            'file_unique_id' => 'job-backup-uniq-id',
            'source_token' => null,
            'file_name' => 'job-backup.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 2048,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 9601], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        $restoreSessionId = DB::table('telegram_filestore_restore_sessions')->insertGetId([
            'source_session_id' => $session->id,
            'source_chat_id' => 7702694790,
            'source_token' => null,
            'source_public_token' => 'filestoebot_1V_jobbackup0001',
            'target_bot_username' => 'new_files_star_bot',
            'target_chat_id' => 8491679630,
            'status' => 'partial',
            'total_files' => 1,
            'processed_files' => 1,
            'success_files' => 1,
            'failed_files' => 0,
            'started_at' => now(),
            'finished_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telegram_filestore_restore_files')->insert([
            'restore_session_id' => $restoreSessionId,
            'source_session_id' => $session->id,
            'source_file_row_id' => $sourceFile->id,
            'source_chat_id' => 7702694790,
            'source_message_id' => 9601,
            'source_file_id' => 'BAAC-job-backup-file-id',
            'source_file_unique_id' => 'job-backup-uniq-id',
            'source_token' => null,
            'source_public_token' => 'filestoebot_1V_jobbackup0001',
            'forwarded_message_id' => 9701,
            'target_chat_id' => 8491679630,
            'target_message_id' => 9702,
            'target_file_id' => 'BAAC-job-restored-file-id',
            'target_file_unique_id' => 'job-restored-uniq-id',
            'file_name' => 'job-backup.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 2048,
            'file_type' => 'document',
            'status' => 'synced',
            'attempt_count' => 1,
            'raw_payload' => '{}',
            'forwarded_at' => now(),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new SendFilestoreSessionFilesJob((int) $session->id, 8491679630, 'backup_restore');
        $job->handle();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/botbackup-restore-test-token/sendDocument'
                && (int) ($request['chat_id'] ?? 0) === 8491679630
                && (string) ($request['document'] ?? '') === 'BAAC-job-restored-file-id';
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), 'botfilestore-test-token/');
        });

        Http::assertNotSent(function ($request): bool {
            return (string) ($request['document'] ?? '') === 'BAAC-job-backup-file-id';
        });
    }
}

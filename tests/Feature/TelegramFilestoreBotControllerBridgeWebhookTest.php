<?php

namespace Tests\Feature;

use App\Jobs\TelegramFilestoreDebouncedPromptJob;
use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use App\Services\TelegramFilestoreBridgeContextService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramFilestoreBotControllerBridgeWebhookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

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
}

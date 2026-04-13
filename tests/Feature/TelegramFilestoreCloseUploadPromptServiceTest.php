<?php

namespace Tests\Feature;

use App\Services\TelegramFilestoreBotProfileResolver;
use App\Services\TelegramFilestoreCloseUploadPromptService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramFilestoreCloseUploadPromptServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('telegram.filestore_bot_token', 'filestore-test-token');
        config()->set('telegram.filestore_bot_username', 'filestoebot');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('telegram_filestore_files');
        Schema::dropIfExists('telegram_filestore_sessions');

        Schema::create('telegram_filestore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('username')->nullable();
            $table->string('public_token')->nullable();
            $table->string('source_token')->nullable();
            $table->string('status')->default('uploading');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedBigInteger('total_size')->default(0);
            $table->dateTime('close_upload_prompted_at')->nullable();
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
    }

    public function test_service_sends_new_prompt_when_none_exists(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ], 200),
        ]);

        $sessionId = $this->createUploadingSessionWithFiles();

        $result = app(TelegramFilestoreCloseUploadPromptService::class)->sendOrRefreshPrompt(
            $sessionId,
            8491679630
        );

        $this->assertSame('sent', $result['action']);
        $this->assertSame(501, $result['message_id']);
        $this->assertSame(
            501,
            Cache::get('filestore_close_upload_prompt_message_id_' . $sessionId)
        );

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && (int) ($request['chat_id'] ?? 0) === 8491679630
                && str_contains((string) ($request['text'] ?? ''), '影片：2')
                && str_contains((string) ($request['text'] ?? ''), '是否結束上傳');
        });
    }

    public function test_service_replaces_old_prompt_with_new_message_when_requested(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => function ($request) {
                if (str_contains($request->url(), '/deleteMessage')) {
                    return Http::response(['ok' => true], 200);
                }

                if (str_contains($request->url(), '/sendMessage')) {
                    return Http::response([
                        'ok' => true,
                        'result' => ['message_id' => 777],
                    ], 200);
                }

                return Http::response(['ok' => false], 400);
            },
        ]);

        $sessionId = $this->createUploadingSessionWithFiles();
        Cache::put('filestore_close_upload_prompt_message_id_' . $sessionId, 333, now()->addMinutes(10));

        $result = app(TelegramFilestoreCloseUploadPromptService::class)->sendOrRefreshPrompt(
            $sessionId,
            8491679630,
            TelegramFilestoreBotProfileResolver::FILESTORE,
            true
        );

        $this->assertSame('resent', $result['action']);
        $this->assertSame(777, $result['message_id']);
        $this->assertSame(
            777,
            Cache::get('filestore_close_upload_prompt_message_id_' . $sessionId)
        );

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/deleteMessage')
                && (int) ($request['message_id'] ?? 0) === 333;
        });

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && (int) ($request['chat_id'] ?? 0) === 8491679630;
        });
    }

    private function createUploadingSessionWithFiles(): int
    {
        $sessionId = DB::table('telegram_filestore_sessions')->insertGetId([
            'chat_id' => 8491679630,
            'username' => 's4546663',
            'status' => 'uploading',
            'total_files' => 2,
            'total_size' => 3000,
            'created_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            [
                'session_id' => $sessionId,
                'chat_id' => 8491679630,
                'message_id' => 1,
                'file_id' => 'f1',
                'file_unique_id' => 'u1',
                'file_size' => 1000,
                'file_type' => 'video',
                'created_at' => now(),
            ],
            [
                'session_id' => $sessionId,
                'chat_id' => 8491679630,
                'message_id' => 2,
                'file_id' => 'f2',
                'file_unique_id' => 'u2',
                'file_size' => 2000,
                'file_type' => 'video',
                'created_at' => now(),
            ],
        ]);

        return (int) $sessionId;
    }
}

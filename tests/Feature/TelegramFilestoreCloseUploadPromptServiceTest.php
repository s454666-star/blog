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
        $this->assertNotNull(Cache::get('filestore_close_upload_prompt_created_at_' . $sessionId));

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
        $this->assertNotNull(Cache::get('filestore_close_upload_prompt_created_at_' . $sessionId));

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/deleteMessage')
                && (int) ($request['message_id'] ?? 0) === 333;
        });

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && (int) ($request['chat_id'] ?? 0) === 8491679630;
        });
    }

    public function test_service_treats_message_not_modified_as_successful_edit(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => function ($request) {
                if (str_contains($request->url(), '/editMessageText')) {
                    return Http::response([
                        'ok' => false,
                        'error_code' => 400,
                        'description' => 'Bad Request: message is not modified',
                    ], 400);
                }

                return Http::response([
                    'ok' => true,
                    'result' => ['message_id' => 999],
                ], 200);
            },
        ]);

        $sessionId = $this->createUploadingSessionWithFiles();
        Cache::put('filestore_close_upload_prompt_message_id_' . $sessionId, 333, now()->addMinutes(10));
        Cache::put(
            'filestore_close_upload_prompt_created_at_' . $sessionId,
            now()->subSeconds(30)->toIso8601String(),
            now()->addMinutes(10)
        );

        $result = app(TelegramFilestoreCloseUploadPromptService::class)->sendOrRefreshPrompt(
            $sessionId,
            8491679630
        );

        $this->assertSame('edited', $result['action']);
        $this->assertSame(333, $result['message_id']);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/editMessageText')
                && (int) ($request['message_id'] ?? 0) === 333;
        });
    }

    public function test_service_prefers_fresh_prompt_when_cached_message_is_legacy(): void
    {
        $sessionId = $this->createUploadingSessionWithFiles();
        Cache::put('filestore_close_upload_prompt_message_id_' . $sessionId, 333, now()->addMinutes(10));

        $this->assertTrue(
            app(TelegramFilestoreCloseUploadPromptService::class)->shouldPreferFreshPromptMessage(
                $this->findSession($sessionId)
            )
        );
    }

    public function test_service_prefers_fresh_prompt_when_existing_message_is_old(): void
    {
        $sessionId = $this->createUploadingSessionWithFiles();
        Cache::put('filestore_close_upload_prompt_message_id_' . $sessionId, 333, now()->addMinutes(10));
        Cache::put(
            'filestore_close_upload_prompt_created_at_' . $sessionId,
            now()->subMinutes(2)->toIso8601String(),
            now()->addMinutes(10)
        );

        $this->assertTrue(
            app(TelegramFilestoreCloseUploadPromptService::class)->shouldPreferFreshPromptMessage(
                $this->findSession($sessionId)
            )
        );
    }

    public function test_service_keeps_editing_recent_prompt_messages(): void
    {
        $sessionId = $this->createUploadingSessionWithFiles();
        Cache::put('filestore_close_upload_prompt_message_id_' . $sessionId, 333, now()->addMinutes(10));
        Cache::put(
            'filestore_close_upload_prompt_created_at_' . $sessionId,
            now()->subSeconds(30)->toIso8601String(),
            now()->addMinutes(10)
        );

        $this->assertFalse(
            app(TelegramFilestoreCloseUploadPromptService::class)->shouldPreferFreshPromptMessage(
                $this->findSession($sessionId)
            )
        );
    }

    public function test_service_recognizes_old_uploading_session_with_missing_prompt_for_rescue(): void
    {
        $sessionId = $this->createUploadingSessionWithFiles(now()->subHours(5), now()->subHours(5));

        $this->assertTrue(
            app(TelegramFilestoreCloseUploadPromptService::class)->shouldRescueMissingPrompt(
                $this->findSession($sessionId)
            )
        );
    }

    public function test_service_rescues_missing_prompt_for_old_uploading_session(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 909],
            ], 200),
        ]);

        $sessionId = $this->createUploadingSessionWithFiles(now()->subHours(5), now()->subHours(5));

        $result = app(TelegramFilestoreCloseUploadPromptService::class)->rescueMissingPromptIfNeeded(
            $sessionId,
            8491679630
        );

        $this->assertNotNull($result);
        $this->assertSame('resent', $result['action']);
        $this->assertSame(909, $result['message_id']);
        $this->assertSame(909, Cache::get('filestore_close_upload_prompt_message_id_' . $sessionId));

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/sendMessage')
                && (int) ($request['chat_id'] ?? 0) === 8491679630
                && str_contains((string) ($request['text'] ?? ''), '是否結束上傳');
        });
    }

    private function createUploadingSessionWithFiles($createdAt = null, $closeUploadPromptedAt = null): int
    {
        $sessionId = DB::table('telegram_filestore_sessions')->insertGetId([
            'chat_id' => 8491679630,
            'username' => 's4546663',
            'status' => 'uploading',
            'total_files' => 2,
            'total_size' => 3000,
            'created_at' => $createdAt ?? now(),
            'close_upload_prompted_at' => $closeUploadPromptedAt,
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

    private function findSession(int $sessionId): object
    {
        return \App\Models\TelegramFilestoreSession::query()->findOrFail($sessionId);
    }
}

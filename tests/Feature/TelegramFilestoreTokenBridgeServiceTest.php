<?php

namespace Tests\Feature;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use App\Services\TelegramFilestoreTokenBridgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramFilestoreTokenBridgeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('telegram.filestore_sync_chat_id', 7702694790);
        config()->set('telegram.filestore_sync_bot_username', 'filestoebot');

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

    public function test_sync_deletes_sent_token_message_as_part_of_source_cleanup(): void
    {
        $token = 'mtfxqbot_4V_testtoken0001';
        $sourceChatId = 123456;
        $sentMessageId = 321;
        $sourceFileMessageIds = [401, 402];
        $sourceReplyOnlyMessageId = 350;
        $forwardedMessageIds = [9001, 9002];
        $bridgeControlMessageId = 8999;

        Http::fake(function ($request) use (
            $token,
            $sourceChatId,
            $sentMessageId,
            $sourceFileMessageIds,
            $sourceReplyOnlyMessageId,
            $forwardedMessageIds,
            $bridgeControlMessageId
        ) {
            if ($request->url() === 'http://127.0.0.1:8000/bots/files') {
                $this->assertSame('mtfxqbot', $request['bot_username']);
                $this->assertSame($sentMessageId, $request['min_message_id']);

                return Http::response([
                    'status' => 'ok',
                    'files_unique_count' => 2,
                    'files_total_bytes' => 300,
                    'files' => [
                        [
                            'chat_id' => $sourceChatId,
                            'message_id' => $sourceFileMessageIds[0],
                            'file_id' => 'src-file-1',
                            'file_unique_id' => 'src-uniq-1',
                            'file_size' => 100,
                            'file_type' => 'document',
                        ],
                        [
                            'chat_id' => $sourceChatId,
                            'message_id' => $sourceFileMessageIds[1],
                            'file_id' => 'src-file-2',
                            'file_unique_id' => 'src-uniq-2',
                            'file_size' => 200,
                            'file_type' => 'video',
                        ],
                    ],
                ], 200);
            }

            if (str_starts_with($request->url(), 'http://127.0.0.1:8000/bots/replies')) {
                $this->assertSame('mtfxqbot', $request['bot_username']);
                $this->assertSame($sentMessageId, (int) $request['min_message_id']);
                $this->assertSame(5000, (int) $request['limit']);

                return Http::response([
                    [
                        'message_id' => $sourceReplyOnlyMessageId,
                        'chat_id' => $sourceChatId,
                        'text_preview' => '请选择要获取的文件类型',
                    ],
                    [
                        'message_id' => $sourceFileMessageIds[0],
                        'chat_id' => $sourceChatId,
                        'text_preview' => 'file 1',
                    ],
                    [
                        'message_id' => $sourceFileMessageIds[1],
                        'chat_id' => $sourceChatId,
                        'text_preview' => 'file 2',
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/forward-messages') {
                $this->assertSame($sourceChatId, $request['source_chat_id']);
                $this->assertSame($sourceFileMessageIds, $request['message_ids']);
                $this->assertSame('filestoebot', $request['target_bot_username']);
                $this->assertStringContainsString('filestorebridge|', (string) $request['bridge_control_text']);
                $this->assertStringContainsString($token, (string) $request['bridge_control_text']);

                $session = TelegramFilestoreSession::query()
                    ->where('source_token', $token)
                    ->where('status', 'uploading')
                    ->firstOrFail();

                foreach ($forwardedMessageIds as $index => $messageId) {
                    TelegramFilestoreFile::query()->create([
                        'session_id' => (int) $session->id,
                        'chat_id' => 7702694790,
                        'message_id' => $messageId,
                        'file_id' => 'BAAC-test-' . $index,
                        'file_unique_id' => 'dest-uniq-' . $index,
                        'source_token' => null,
                        'file_name' => 'file-' . $index . '.bin',
                        'mime_type' => 'application/octet-stream',
                        'file_size' => 100 + ($index * 50),
                        'file_type' => $index === 0 ? 'document' : 'video',
                        'raw_payload' => json_encode(['message_id' => $messageId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at' => now(),
                    ]);
                }

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => $forwardedMessageIds,
                    'bridge_control_message_id' => $bridgeControlMessageId,
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/delete-messages') {
                if ($request['chat_peer'] === 'filestoebot') {
                    $expectedMessageIds = array_merge($forwardedMessageIds, [$bridgeControlMessageId]);
                    sort($expectedMessageIds);

                    $actualMessageIds = array_map('intval', (array) $request['message_ids']);
                    sort($actualMessageIds);

                    $this->assertSame($expectedMessageIds, $actualMessageIds);

                    return Http::response([
                        'status' => 'ok',
                        'deleted_count' => count($forwardedMessageIds) + 1,
                    ], 200);
                }

                if ($request['chat_peer'] === 'mtfxqbot') {
                    $expectedMessageIds = array_merge([$sentMessageId, $sourceReplyOnlyMessageId], $sourceFileMessageIds);
                    sort($expectedMessageIds);

                    $actualMessageIds = array_map('intval', (array) $request['message_ids']);
                    sort($actualMessageIds);

                    $this->assertSame($expectedMessageIds, $actualMessageIds);

                    return Http::response([
                        'status' => 'ok',
                        'deleted_count' => 4,
                    ], 200);
                }
            }

            return Http::response([
                'status' => 'error',
                'reason' => 'unexpected_request',
                'url' => $request->url(),
            ], 500);
        });

        $result = app(TelegramFilestoreTokenBridgeService::class)
            ->sync($token, 'http://127.0.0.1:8000', 'mtfxqbot', $sentMessageId, true);

        $this->assertTrue($result['ok']);
        $this->assertSame('synced', $result['status']);
        $this->assertSame(2, $result['stored_files']);
        $this->assertStringContainsString('target=@filestoebot', (string) $result['summary']);
        $this->assertStringContainsString('forwarded=2', (string) $result['summary']);
        $this->assertStringContainsString('deleted_forwarded=yes', (string) $result['summary']);
        $this->assertStringContainsString('deleted_source=yes', (string) $result['summary']);

        $session = TelegramFilestoreSession::query()
            ->where('source_token', $token)
            ->firstOrFail();

        $this->assertSame('closed', $session->status);
        $this->assertSame(2, (int) $session->total_files);
        $this->assertSame(2, TelegramFilestoreFile::query()->where('session_id', $session->id)->count());
        $this->assertSame(2, TelegramFilestoreFile::query()->where('session_id', $session->id)->where('source_token', $token)->count());
    }

    public function test_sync_cleans_stale_uploading_session_for_sync_chat_before_fetching_files(): void
    {
        $staleSession = TelegramFilestoreSession::query()->create([
            'chat_id' => 7702694790,
            'username' => null,
            'encrypt_token' => null,
            'public_token' => null,
            'source_token' => 'mtfxqbot_old_stale',
            'status' => 'uploading',
            'total_files' => 1,
            'total_size' => 300,
            'share_count' => 0,
            'created_at' => now()->subDays(2),
        ]);

        TelegramFilestoreFile::query()->create([
            'session_id' => (int) $staleSession->id,
            'chat_id' => 7702694790,
            'message_id' => 7001,
            'file_id' => 'STALE-FILE-ID',
            'file_unique_id' => 'stale-bridge-uniq',
            'source_token' => 'mtfxqbot_old_stale',
            'file_name' => 'stale.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 300,
            'file_type' => 'document',
            'raw_payload' => '{}',
            'created_at' => now()->subDays(2),
        ]);

        Http::fake(function ($request) {
            if ($request->url() === 'http://127.0.0.1:8000/bots/files') {
                return Http::response([
                    'status' => 'ok',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'files' => [],
                ], 200);
            }

            return Http::response([
                'status' => 'error',
                'reason' => 'unexpected_request',
                'url' => $request->url(),
            ], 500);
        });

        $result = app(TelegramFilestoreTokenBridgeService::class)
            ->sync('mtfxqbot_4V_cleanup0001', 'http://127.0.0.1:8000', 'mtfxqbot');

        $this->assertTrue($result['ok']);
        $this->assertSame('no_files', $result['status']);
        $this->assertDatabaseMissing('telegram_filestore_sessions', ['id' => (int) $staleSession->id]);
        $this->assertDatabaseMissing('telegram_filestore_files', ['file_unique_id' => 'stale-bridge-uniq']);
        $this->assertDatabaseCount('telegram_filestore_sessions', 0);
    }
}

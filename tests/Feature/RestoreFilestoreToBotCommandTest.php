<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RestoreFilestoreToBotCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('telegram.backup_restore_bot_username', 'file_backup_restore_bot');
        config()->set('telegram.backup_restore_bot_token', 'restore-token');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('telegram_filestore_restore_files');
        Schema::dropIfExists('telegram_filestore_restore_sessions');
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

    public function test_command_forwards_source_file_and_records_new_bot_file_ids(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            'id' => 55,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_3V_demo123',
            'source_token' => 'showfilesbot_3V_demo123',
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 88,
            'session_id' => 55,
            'chat_id' => 7702694790,
            'message_id' => 6001,
            'file_id' => 'OLD-DOC-FILE-ID',
            'file_unique_id' => 'OLD-DOC-UNIQ-ID',
            'source_token' => 'showfilesbot_3V_demo123',
            'file_name' => 'archive.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 1024,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 6001], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            [
                                'update_id' => 100,
                                'message' => [
                                    'message_id' => 61,
                                    'chat' => [
                                        'id' => 8491679630,
                                        'type' => 'private',
                                    ],
                                    'text' => '/start',
                                ],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'ok' => true,
                    'result' => [
                        [
                            'update_id' => 101,
                            'message' => [
                                'message_id' => 62,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'document' => [
                                    'file_id' => 'NEW-DOC-FILE-ID',
                                    'file_unique_id' => 'NEW-DOC-UNIQ-ID',
                                    'file_name' => 'archive.bin',
                                    'mime_type' => 'application/octet-stream',
                                    'file_size' => 1024,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                $this->assertSame(7702694790, (int) $request['source_chat_id']);
                $this->assertSame([6001], array_map('intval', (array) $request['message_ids']));
                $this->assertSame('file_backup_restore_bot', (string) $request['target_bot_username']);

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [91001],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=55 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 55,
            'source_token' => 'showfilesbot_3V_demo123',
            'source_public_token' => 'filestoebot_3V_demo123',
            'target_bot_username' => 'file_backup_restore_bot',
            'target_chat_id' => 8491679630,
            'status' => 'completed',
            'total_files' => 1,
            'processed_files' => 1,
            'success_files' => 1,
            'failed_files' => 0,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 55,
            'source_file_row_id' => 88,
            'source_token' => 'showfilesbot_3V_demo123',
            'source_public_token' => 'filestoebot_3V_demo123',
            'forwarded_message_id' => 91001,
            'target_chat_id' => 8491679630,
            'target_message_id' => 62,
            'target_file_id' => 'NEW-DOC-FILE-ID',
            'target_file_unique_id' => 'NEW-DOC-UNIQ-ID',
            'status' => 'synced',
        ]);
    }

    public function test_command_marks_row_failed_when_forward_request_fails(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            'id' => 56,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_4V_demo456',
            'source_token' => 'showfilesbot_4V_demo456',
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 89,
            'session_id' => 56,
            'chat_id' => 7702694790,
            'message_id' => 6002,
            'file_id' => 'OLD-VIDEO-FILE-ID',
            'file_unique_id' => 'OLD-VIDEO-UNIQ-ID',
            'source_token' => 'showfilesbot_4V_demo456',
            'file_name' => 'archive.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 2048,
            'file_type' => 'video',
            'raw_payload' => json_encode(['message_id' => 6002], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        [
                            'update_id' => 200,
                            'message' => [
                                'message_id' => 71,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'text' => '/start',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                return Http::response([
                    'status' => 'error',
                    'reason' => 'forward_failed',
                ], 500);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=56 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('failed=1')
            ->assertExitCode(1);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 56,
            'source_token' => 'showfilesbot_4V_demo456',
            'status' => 'failed',
            'total_files' => 1,
            'processed_files' => 1,
            'success_files' => 0,
            'failed_files' => 1,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 56,
            'source_file_row_id' => 89,
            'source_token' => 'showfilesbot_4V_demo456',
            'status' => 'failed',
        ]);
    }

    public function test_command_falls_back_to_source_bot_api_when_forwarded_source_message_is_missing(): void
    {
        config()->set('telegram.filestore_bot_token', 'source-token');

        DB::table('telegram_filestore_sessions')->insert([
            'id' => 57,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_5V_demo789',
            'source_token' => 'showfilesbot_5V_demo789',
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 90,
            'session_id' => 57,
            'chat_id' => 7702694790,
            'message_id' => 6003,
            'file_id' => 'OLD-DOCUMENT-FILE-ID',
            'file_unique_id' => 'OLD-DOCUMENT-UNIQ-ID',
            'source_token' => 'showfilesbot_5V_demo789',
            'file_name' => 'restore.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 4096,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 6003], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        [
                            'update_id' => 300,
                            'message' => [
                                'message_id' => 81,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'text' => '/start',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                return Http::response([
                    'status' => 'error',
                    'reason' => 'source_messages_not_found',
                    'missing_message_ids' => [6003],
                ], 500);
            }

            if (str_starts_with($request->url(), 'https://api.telegram.org/botsource-token/getFile')) {
                $this->assertSame('OLD-DOCUMENT-FILE-ID', (string) $request['file_id']);

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'file_path' => 'documents/file_1.bin',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.telegram.org/file/botsource-token/documents/file_1.bin') {
                return Http::response('binary-document-content', 200);
            }

            if ($request->url() === 'https://api.telegram.org/botrestore-token/sendDocument') {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 82,
                        'chat' => [
                            'id' => 8491679630,
                            'type' => 'private',
                        ],
                        'document' => [
                            'file_id' => 'RESTORED-DOC-FILE-ID',
                            'file_unique_id' => 'RESTORED-DOC-UNIQ-ID',
                            'file_name' => 'restore.bin',
                            'mime_type' => 'application/octet-stream',
                            'file_size' => 4096,
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=57 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 57,
            'source_token' => 'showfilesbot_5V_demo789',
            'status' => 'completed',
            'success_files' => 1,
            'failed_files' => 0,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 57,
            'source_file_row_id' => 90,
            'source_token' => 'showfilesbot_5V_demo789',
            'target_file_id' => 'RESTORED-DOC-FILE-ID',
            'target_file_unique_id' => 'RESTORED-DOC-UNIQ-ID',
            'status' => 'synced',
        ]);
    }

    public function test_all_mode_skips_existing_rows_and_continues_to_next_session_without_source_token(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            [
                'id' => 58,
                'chat_id' => 7702694790,
                'public_token' => 'filestoebot_existing',
                'source_token' => null,
                'status' => 'closed',
                'total_files' => 1,
                'created_at' => now(),
                'closed_at' => now(),
            ],
            [
                'id' => 59,
                'chat_id' => 7702694790,
                'public_token' => 'filestoebot_new',
                'source_token' => null,
                'status' => 'uploading',
                'total_files' => 1,
                'created_at' => now(),
                'closed_at' => null,
            ],
        ]);

        DB::table('telegram_filestore_files')->insert([
            [
                'id' => 91,
                'session_id' => 58,
                'chat_id' => 7702694790,
                'message_id' => 6004,
                'file_id' => 'EXISTING-FILE-ID',
                'file_unique_id' => 'EXISTING-UNIQ-ID',
                'source_token' => null,
                'file_name' => 'existing.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 10,
                'file_type' => 'document',
                'raw_payload' => json_encode(['message_id' => 6004], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ],
            [
                'id' => 92,
                'session_id' => 59,
                'chat_id' => 7702694790,
                'message_id' => 6005,
                'file_id' => 'NEW-FILE-ID',
                'file_unique_id' => 'NEW-UNIQ-ID',
                'source_token' => null,
                'file_name' => 'new.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 11,
                'file_type' => 'document',
                'raw_payload' => json_encode(['message_id' => 6005], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ],
        ]);

        $existingRestoreSessionId = DB::table('telegram_filestore_restore_sessions')->insertGetId([
            'source_session_id' => 58,
            'source_chat_id' => 7702694790,
            'source_token' => null,
            'source_public_token' => 'filestoebot_existing',
            'target_bot_username' => 'file_backup_restore_bot',
            'target_chat_id' => 8491679630,
            'status' => 'completed',
            'total_files' => 1,
            'processed_files' => 1,
            'success_files' => 1,
            'failed_files' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telegram_filestore_restore_files')->insert([
            'restore_session_id' => $existingRestoreSessionId,
            'source_session_id' => 58,
            'source_file_row_id' => 91,
            'source_chat_id' => 7702694790,
            'source_message_id' => 6004,
            'source_file_id' => 'EXISTING-FILE-ID',
            'source_file_unique_id' => 'EXISTING-UNIQ-ID',
            'source_token' => null,
            'source_public_token' => 'filestoebot_existing',
            'target_chat_id' => 8491679630,
            'target_message_id' => 501,
            'target_file_id' => 'RESTORED-EXISTING-FILE-ID',
            'target_file_unique_id' => 'RESTORED-EXISTING-UNIQ-ID',
            'file_name' => 'existing.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 10,
            'file_type' => 'document',
            'status' => 'synced',
            'attempt_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            [
                                'update_id' => 400,
                                'message' => [
                                    'message_id' => 91,
                                    'chat' => [
                                        'id' => 8491679630,
                                        'type' => 'private',
                                    ],
                                    'text' => '/start',
                                ],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'ok' => true,
                    'result' => [
                        [
                            'update_id' => 401,
                            'message' => [
                                'message_id' => 92,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'document' => [
                                    'file_id' => 'NEW-RESTORED-FILE-ID',
                                    'file_unique_id' => 'NEW-RESTORED-UNIQ-ID',
                                    'file_name' => 'new.bin',
                                    'mime_type' => 'application/octet-stream',
                                    'file_size' => 11,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                $this->assertSame([6005], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [92001],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --all --limit=1 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 59,
            'source_public_token' => 'filestoebot_new',
            'source_token' => null,
            'target_bot_username' => 'file_backup_restore_bot',
            'success_files' => 1,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 59,
            'source_file_row_id' => 92,
            'source_token' => null,
            'target_file_id' => 'NEW-RESTORED-FILE-ID',
            'status' => 'synced',
        ]);
    }
}

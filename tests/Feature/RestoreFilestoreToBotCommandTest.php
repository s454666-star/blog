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

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                $this->assertSame('file_backup_restore_bot', (string) $request['chat_peer']);
                $this->assertSame([91001], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
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
        $forwardAttempts = 0;

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

        Http::fake(function ($request) use (&$forwardAttempts) {
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
                $forwardAttempts++;

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
            ->expectsOutputToContain('giving up after 3 attempts')
            ->assertExitCode(1);

        $this->assertSame(3, $forwardAttempts);

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
            'attempt_count' => 3,
        ]);
    }

    public function test_command_retries_same_file_until_synced_before_processing_next_file(): void
    {
        $forwardCalls = [];
        $capturePayloads = [
            [
                'update_id' => 701,
                'message_id' => 131,
                'document' => [
                    'file_id' => 'RETRY-FIRST-TARGET-FILE-ID',
                    'file_unique_id' => 'RETRY-FIRST-TARGET-UNIQ-ID',
                    'file_name' => 'first.bin',
                    'mime_type' => 'application/octet-stream',
                    'file_size' => 13,
                ],
            ],
            [
                'update_id' => 702,
                'message_id' => 132,
                'document' => [
                    'file_id' => 'RETRY-SECOND-TARGET-FILE-ID',
                    'file_unique_id' => 'RETRY-SECOND-TARGET-UNIQ-ID',
                    'file_name' => 'second.bin',
                    'mime_type' => 'application/octet-stream',
                    'file_size' => 14,
                ],
            ],
        ];

        DB::table('telegram_filestore_sessions')->insert([
            'id' => 156,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_retry_in_order',
            'source_token' => 'showfilesbot_retry_in_order',
            'status' => 'closed',
            'total_files' => 2,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            [
                'id' => 189,
                'session_id' => 156,
                'chat_id' => 7702694790,
                'message_id' => 6101,
                'file_id' => 'RETRY-FIRST-SOURCE-FILE-ID',
                'file_unique_id' => 'RETRY-FIRST-SOURCE-UNIQ-ID',
                'source_token' => 'showfilesbot_retry_in_order',
                'file_name' => 'first.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 13,
                'file_type' => 'document',
                'raw_payload' => json_encode(['message_id' => 6101], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ],
            [
                'id' => 190,
                'session_id' => 156,
                'chat_id' => 7702694790,
                'message_id' => 6102,
                'file_id' => 'RETRY-SECOND-SOURCE-FILE-ID',
                'file_unique_id' => 'RETRY-SECOND-SOURCE-UNIQ-ID',
                'source_token' => 'showfilesbot_retry_in_order',
                'file_name' => 'second.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 14,
                'file_type' => 'document',
                'raw_payload' => json_encode(['message_id' => 6102], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ],
        ]);

        Http::fake(function ($request) use (&$forwardCalls, &$capturePayloads) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            [
                                'update_id' => 700,
                                'message' => [
                                    'message_id' => 130,
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

                $capturePayload = array_shift($capturePayloads);

                return Http::response([
                    'ok' => true,
                    'result' => $capturePayload === null ? [] : [[
                        'update_id' => $capturePayload['update_id'],
                        'message' => [
                            'message_id' => $capturePayload['message_id'],
                            'chat' => [
                                'id' => 8491679630,
                                'type' => 'private',
                            ],
                            'document' => $capturePayload['document'],
                        ],
                    ]],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                $messageId = (int) ((array) $request['message_ids'])[0];
                $forwardCalls[] = $messageId;

                if ($messageId === 6101 && count(array_filter($forwardCalls, static fn (int $id): bool => $id === 6101)) < 3) {
                    return Http::response([
                        'status' => 'error',
                        'reason' => 'forward_failed',
                    ], 500);
                }

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [$messageId + 88000],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=156 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('synced=2')
            ->assertExitCode(0);

        $this->assertSame([6101, 6101, 6101, 6102], $forwardCalls);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 156,
            'status' => 'completed',
            'total_files' => 2,
            'processed_files' => 2,
            'success_files' => 2,
            'failed_files' => 0,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 156,
            'source_file_row_id' => 189,
            'status' => 'synced',
            'attempt_count' => 3,
            'target_file_id' => 'RETRY-FIRST-TARGET-FILE-ID',
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 156,
            'source_file_row_id' => 190,
            'status' => 'synced',
            'attempt_count' => 1,
            'target_file_id' => 'RETRY-SECOND-TARGET-FILE-ID',
        ]);
    }

    public function test_command_retries_existing_failed_row_on_subsequent_run(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            'id' => 157,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_resume_failed',
            'source_token' => 'showfilesbot_resume_failed',
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 191,
            'session_id' => 157,
            'chat_id' => 7702694790,
            'message_id' => 6201,
            'file_id' => 'RESUME-SOURCE-FILE-ID',
            'file_unique_id' => 'RESUME-SOURCE-UNIQ-ID',
            'source_token' => 'showfilesbot_resume_failed',
            'file_name' => 'resume.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 15,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 6201], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        $existingRestoreSessionId = DB::table('telegram_filestore_restore_sessions')->insertGetId([
            'source_session_id' => 157,
            'source_chat_id' => 7702694790,
            'source_token' => 'showfilesbot_resume_failed',
            'source_public_token' => 'filestoebot_resume_failed',
            'target_bot_username' => 'file_backup_restore_bot',
            'target_chat_id' => 8491679630,
            'status' => 'completed_with_failures',
            'total_files' => 1,
            'processed_files' => 1,
            'success_files' => 0,
            'failed_files' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('telegram_filestore_restore_files')->insert([
            'restore_session_id' => $existingRestoreSessionId,
            'source_session_id' => 157,
            'source_file_row_id' => 191,
            'source_chat_id' => 7702694790,
            'source_message_id' => 6201,
            'source_file_id' => 'RESUME-SOURCE-FILE-ID',
            'source_file_unique_id' => 'RESUME-SOURCE-UNIQ-ID',
            'source_token' => 'showfilesbot_resume_failed',
            'source_public_token' => 'filestoebot_resume_failed',
            'file_name' => 'resume.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 15,
            'file_type' => 'document',
            'status' => 'failed',
            'attempt_count' => 1,
            'last_error' => 'forward failed',
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
                                'update_id' => 800,
                                'message' => [
                                    'message_id' => 140,
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
                            'update_id' => 801,
                            'message' => [
                                'message_id' => 141,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'document' => [
                                    'file_id' => 'RESUME-TARGET-FILE-ID',
                                    'file_unique_id' => 'RESUME-TARGET-UNIQ-ID',
                                    'file_name' => 'resume.bin',
                                    'mime_type' => 'application/octet-stream',
                                    'file_size' => 15,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                $this->assertSame([6201], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [95001],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=157 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 157,
            'status' => 'completed',
            'processed_files' => 1,
            'success_files' => 1,
            'failed_files' => 0,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'restore_session_id' => $existingRestoreSessionId,
            'source_session_id' => 157,
            'source_file_row_id' => 191,
            'status' => 'synced',
            'attempt_count' => 2,
            'target_file_id' => 'RESUME-TARGET-FILE-ID',
        ]);
    }

    public function test_command_uses_sync_worker_for_large_targeted_file_when_preferred_base_cannot_replay(): void
    {
        config()->set('telegram.filestore_bot_token', 'source-token');
        config()->set('telegram.filestore_sync_bot_username', 'filestoebot');
        config()->set('telegram.filestore_sync_chat_id', 7702694790);

        DB::table('telegram_filestore_sessions')->insert([
            'id' => 158,
            'chat_id' => 652667665,
            'public_token' => 'filestoebot_targeted_large',
            'source_token' => null,
            'status' => 'closed',
            'total_files' => 2,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            [
                'id' => 201,
                'session_id' => 158,
                'chat_id' => 652667665,
                'message_id' => 92001,
                'file_id' => 'LARGE-SOURCE-FILE-ID',
                'file_unique_id' => 'LARGE-SOURCE-UNIQ-ID',
                'source_token' => null,
                'file_name' => 'large-targeted.mp4',
                'mime_type' => 'video/mp4',
                'file_size' => 30878433,
                'file_type' => 'video',
                'raw_payload' => json_encode(['message_id' => 92001], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ],
            [
                'id' => 202,
                'session_id' => 158,
                'chat_id' => 652667665,
                'message_id' => 92002,
                'file_id' => 'OTHER-SOURCE-FILE-ID',
                'file_unique_id' => 'OTHER-SOURCE-UNIQ-ID',
                'source_token' => null,
                'file_name' => 'other.mp4',
                'mime_type' => 'video/mp4',
                'file_size' => 30878434,
                'file_type' => 'video',
                'raw_payload' => json_encode(['message_id' => 92002], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ],
        ]);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            [
                                'update_id' => 900,
                                'message' => [
                                    'message_id' => 150,
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
                            'update_id' => 901,
                            'message' => [
                                'message_id' => 151,
                                'chat' => [
                                    'id' => 7702694790,
                                    'type' => 'private',
                                ],
                                'video' => [
                                    'file_id' => 'TARGET-LARGE-FILE-ID',
                                    'file_unique_id' => 'TARGET-LARGE-UNIQ-ID',
                                    'file_name' => 'large-targeted.mp4',
                                    'mime_type' => 'video/mp4',
                                    'file_size' => 30878433,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                return Http::response([
                    'status' => 'error',
                    'reason' => 'forward_failed',
                    'error' => 'Could not find the input entity for PeerUser(user_id=652667665) (PeerUser).',
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/files') {
                return Http::response([
                    'status' => 'ok',
                    'source_chat_id' => 0,
                    'files' => [],
                ], 200);
            }

            if ($request->url() === 'https://api.telegram.org/botsource-token/sendVideo') {
                $this->assertSame(7702694790, (int) $request['chat_id']);
                $this->assertSame('LARGE-SOURCE-FILE-ID', (string) $request['video']);

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 99001,
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/files') {
                $minMessageId = (int) ($request['min_message_id'] ?? 0);

                if ($minMessageId <= 0) {
                    return Http::response([
                        'status' => 'ok',
                        'source_chat_id' => 8468381207,
                        'files' => [
                            [
                                'message_id' => 517603,
                                'file_id' => 'OLD-MTPROTO-ID',
                                'file_unique_id' => 'OLD-MTPROTO-UNIQ',
                                'file_name' => 'old-video.mp4',
                                'mime_type' => 'video/mp4',
                                'file_size' => 123,
                                'file_type' => 'video',
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ok',
                    'source_chat_id' => 8468381207,
                    'files' => [
                        [
                            'message_id' => 517604,
                            'file_id' => 'NEW-MTPROTO-ID',
                            'file_unique_id' => 'NEW-MTPROTO-UNIQ',
                            'file_name' => 'large-targeted.mp4',
                            'mime_type' => 'video/mp4',
                            'file_size' => 30878433,
                            'file_type' => 'video',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/send') {
                $this->assertSame('file_backup_restore_bot', (string) $request['bot_username']);
                $this->assertSame('/start', (string) $request['text']);

                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 517608,
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/forward-messages') {
                $this->assertSame(8468381207, (int) $request['source_chat_id']);
                $this->assertSame([517604], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [517605],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/delete-messages') {
                $chatPeer = (string) $request['chat_peer'];
                $messageIds = array_map('intval', (array) $request['message_ids']);

                if ($chatPeer === 'filestoebot') {
                    $this->assertSame([517604], $messageIds);
                } elseif ($chatPeer === 'file_backup_restore_bot') {
                    $this->assertSame([517605], $messageIds);
                } else {
                    $this->fail('Unexpected delete chat peer: ' . $chatPeer);
                }

                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=158 --source-file-row-id=201 --base-uri=http://127.0.0.1:8001 --source-bot-token=source-token --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('fallback resent via sync bot')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 158,
            'source_file_row_id' => 201,
            'status' => 'synced',
            'attempt_count' => 1,
            'target_file_id' => 'TARGET-LARGE-FILE-ID',
        ]);

        $this->assertDatabaseMissing('telegram_filestore_restore_files', [
            'source_session_id' => 158,
            'source_file_row_id' => 202,
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

            if ($request->url() === 'https://api.telegram.org/botrestore-token/deleteMessage') {
                $this->assertSame('8491679630', (string) $request['chat_id']);
                $this->assertSame('82', (string) $request['message_id']);

                return Http::response([
                    'ok' => true,
                    'result' => true,
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

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                $this->assertSame('file_backup_restore_bot', (string) $request['chat_peer']);
                $this->assertSame([92001], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
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

    public function test_command_uses_config_target_chat_id_when_option_is_omitted(): void
    {
        config()->set('telegram.backup_restore_target_chat_id', 8491679630);

        DB::table('telegram_filestore_sessions')->insert([
            'id' => 60,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_config_chat_id',
            'source_token' => 'showfilesbot_config_chat_id',
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 93,
            'session_id' => 60,
            'chat_id' => 7702694790,
            'message_id' => 6006,
            'file_id' => 'CONFIG-CHAT-FILE-ID',
            'file_unique_id' => 'CONFIG-CHAT-UNIQ-ID',
            'source_token' => 'showfilesbot_config_chat_id',
            'file_name' => 'config-chat.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 12,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 6006], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [],
                    ], 200);
                }

                return Http::response([
                    'ok' => true,
                    'result' => [
                        [
                            'update_id' => 501,
                            'message' => [
                                'message_id' => 101,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'document' => [
                                    'file_id' => 'CONFIG-TARGET-FILE-ID',
                                    'file_unique_id' => 'CONFIG-TARGET-UNIQ-ID',
                                    'file_name' => 'config-chat.bin',
                                    'mime_type' => 'application/octet-stream',
                                    'file_size' => 12,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [93001],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                $this->assertSame('file_backup_restore_bot', (string) $request['chat_peer']);
                $this->assertSame([93001], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=60 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 60,
            'target_bot_username' => 'file_backup_restore_bot',
            'target_chat_id' => 8491679630,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 60,
            'source_file_row_id' => 93,
            'target_chat_id' => 8491679630,
            'target_file_id' => 'CONFIG-TARGET-FILE-ID',
            'status' => 'synced',
        ]);
    }

    public function test_command_temporarily_disables_and_restores_webhook_when_get_updates_conflicts(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            'id' => 72,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_webhook_conflict',
            'source_token' => 'showfilesbot_webhook_conflict',
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 7201,
            'session_id' => 72,
            'chat_id' => 7702694790,
            'message_id' => 72001,
            'file_id' => 'WEBHOOK-CONFLICT-FILE-ID',
            'file_unique_id' => 'WEBHOOK-CONFLICT-UNIQ-ID',
            'source_token' => 'showfilesbot_webhook_conflict',
            'file_name' => 'webhook-conflict.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 22,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 72001], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);

        $callIndex = 0;
        $firstConflictIndex = null;
        $deleteWebhookIndex = null;
        $setWebhookIndex = null;
        $webhookDeleted = false;

        Http::fake(function ($request) use (&$callIndex, &$firstConflictIndex, &$deleteWebhookIndex, &$setWebhookIndex, &$webhookDeleted) {
            $callIndex++;

            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if (!$webhookDeleted) {
                    $firstConflictIndex = $callIndex;

                    return Http::response([
                        'ok' => false,
                        'error_code' => 409,
                        'description' => "Conflict: can't use getUpdates method while webhook is active; use deleteWebhook to delete the webhook first",
                    ], 409);
                }

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            [
                                'update_id' => 700,
                                'message' => [
                                    'message_id' => 201,
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
                            'update_id' => 701,
                            'message' => [
                                'message_id' => 202,
                                'chat' => [
                                    'id' => 8491679630,
                                    'type' => 'private',
                                ],
                                'document' => [
                                    'file_id' => 'WEBHOOK-CONFLICT-TARGET-FILE-ID',
                                    'file_unique_id' => 'WEBHOOK-CONFLICT-TARGET-UNIQ-ID',
                                    'file_name' => 'webhook-conflict.bin',
                                    'mime_type' => 'application/octet-stream',
                                    'file_size' => 22,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.telegram.org/botrestore-token/getWebhookInfo') {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'url' => 'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star',
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.telegram.org/botrestore-token/deleteWebhook') {
                $deleteWebhookIndex = $callIndex;
                $webhookDeleted = true;

                return Http::response([
                    'ok' => true,
                    'result' => true,
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [97201],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'https://api.telegram.org/botrestore-token/setWebhook') {
                $setWebhookIndex = $callIndex;
                $this->assertSame(
                    'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star',
                    (string) $request['url']
                );

                return Http::response([
                    'ok' => true,
                    'result' => true,
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=72 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('temporarily disabled target bot webhook for getUpdates')
            ->expectsOutputToContain('restored target bot webhook for @file_backup_restore_bot')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertNotNull($firstConflictIndex);
        $this->assertNotNull($deleteWebhookIndex);
        $this->assertNotNull($setWebhookIndex);
        $this->assertTrue($firstConflictIndex < $deleteWebhookIndex);
        $this->assertTrue($deleteWebhookIndex < $setWebhookIndex);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 72,
            'target_bot_username' => 'file_backup_restore_bot',
            'target_chat_id' => 8491679630,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 72,
            'source_file_row_id' => 7201,
            'target_file_id' => 'WEBHOOK-CONFLICT-TARGET-FILE-ID',
            'status' => 'synced',
        ]);
    }

    public function test_command_replays_large_file_via_sync_chat_before_forwarding(): void
    {
        config()->set('telegram.filestore_sync_chat_id', 7702694790);
        config()->set('telegram.filestore_sync_bot_username', 'filestoebot');

        DB::table('telegram_filestore_sessions')->insert([
            'id' => 61,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_sync_replay',
            'source_token' => null,
            'status' => 'closed',
            'total_files' => 1,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_files')->insert([
            'id' => 94,
            'session_id' => 61,
            'chat_id' => 7702694790,
            'message_id' => 7001,
            'file_id' => 'LARGE-VIDEO-FILE-ID',
            'file_unique_id' => 'LARGE-VIDEO-UNIQ-ID',
            'source_token' => null,
            'file_name' => 'large-video.mp4',
            'mime_type' => 'video/mp4',
            'file_size' => 1153839894,
            'file_type' => 'video',
            'raw_payload' => json_encode(['message_id' => 7001], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
                                'update_id' => 600,
                                'message' => [
                                    'message_id' => 111,
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
                            'update_id' => 601,
                            'message' => [
                                'message_id' => 112,
                                'chat' => [
                                    'id' => 7702694790,
                                    'type' => 'private',
                                ],
                                'video' => [
                                    'file_id' => 'TARGET-LARGE-VIDEO-FILE-ID',
                                    'file_unique_id' => 'TARGET-LARGE-VIDEO-UNIQ-ID',
                                    'file_name' => 'large-video.mp4',
                                    'mime_type' => 'video/mp4',
                                    'file_size' => 1153839894,
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                return Http::response([
                    'status' => 'error',
                    'reason' => 'source_messages_not_found',
                    'missing_message_ids' => [7001],
                ], 500);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/files') {
                $minMessageId = (int) ($request['min_message_id'] ?? 0);

                if ($minMessageId <= 0) {
                    return Http::response([
                        'status' => 'ok',
                        'source_chat_id' => 8468381207,
                        'files' => [
                            [
                                'message_id' => 517603,
                                'file_id' => 'OLD-MTPROTO-ID',
                                'file_unique_id' => 'OLD-MTPROTO-UNIQ',
                                'file_name' => 'old-video.mp4',
                                'mime_type' => 'video/mp4',
                                'file_size' => 123,
                                'file_type' => 'video',
                            ],
                        ],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ok',
                    'source_chat_id' => 8468381207,
                    'files' => [
                        [
                            'message_id' => 517604,
                            'file_id' => 'NEW-MTPROTO-ID',
                            'file_unique_id' => 'NEW-MTPROTO-UNIQ',
                            'file_name' => 'large-video.mp4',
                            'mime_type' => 'video/mp4',
                            'file_size' => 1153839894,
                            'file_type' => 'video',
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/send') {
                $this->assertSame('file_backup_restore_bot', (string) $request['bot_username']);
                $this->assertSame('/start', (string) $request['text']);

                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 517608,
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/forward-messages') {
                $this->assertSame(8468381207, (int) $request['source_chat_id']);
                $this->assertSame([517604], array_map('intval', (array) $request['message_ids']));

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [517605],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8000/bots/delete-messages') {
                $chatPeer = (string) $request['chat_peer'];
                $messageIds = array_map('intval', (array) $request['message_ids']);

                if ($chatPeer === 'filestoebot') {
                    $this->assertSame([517604], $messageIds);
                } elseif ($chatPeer === 'file_backup_restore_bot') {
                    $this->assertSame([517605], $messageIds);
                } else {
                    $this->fail('unexpected chat_peer for delete-messages: ' . $chatPeer);
                }

                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => 1,
                    'undeleted_message_ids' => [],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.telegram.org/botsource-token/sendVideo')) {
                $this->assertSame('7702694790', (string) $request['chat_id']);
                $this->assertSame('LARGE-VIDEO-FILE-ID', (string) $request['video']);

                return Http::response([
                    'ok' => true,
                    'result' => [
                        'message_id' => 1235274,
                    ],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --session-id=61 --base-uri=http://127.0.0.1:8001 --source-bot-token=source-token --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('synced=1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'source_session_id' => 61,
            'target_bot_username' => 'file_backup_restore_bot',
            'status' => 'completed',
            'success_files' => 1,
            'failed_files' => 0,
        ]);

        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'source_session_id' => 61,
            'source_file_row_id' => 94,
            'source_token' => null,
            'forwarded_message_id' => 517605,
            'target_chat_id' => 7702694790,
            'target_file_id' => 'TARGET-LARGE-VIDEO-FILE-ID',
            'target_file_unique_id' => 'TARGET-LARGE-VIDEO-UNIQ-ID',
            'status' => 'synced',
        ]);
    }

    public function test_command_cleans_stale_restore_sessions_before_dry_run(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            'id' => 55,
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_3V_dryrun001',
            'source_token' => 'showfilesbot_3V_dryrun001',
            'status' => 'closed',
            'total_files' => 0,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        DB::table('telegram_filestore_restore_sessions')->insert([
            'id' => 700,
            'source_session_id' => 999,
            'target_bot_username' => 'file_backup_restore_bot',
            'status' => 'running',
            'total_files' => 1,
            'processed_files' => 0,
            'success_files' => 0,
            'failed_files' => 0,
            'started_at' => now()->subDays(2),
            'finished_at' => null,
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        DB::table('telegram_filestore_restore_files')->insert([
            'id' => 701,
            'restore_session_id' => 700,
            'source_session_id' => 999,
            'source_file_row_id' => 1,
            'status' => 'pending',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $this->artisan('filestore:restore-to-bot --session-id=55 --dry-run --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->assertExitCode(0);

        $this->assertDatabaseHas('telegram_filestore_restore_sessions', [
            'id' => 700,
            'status' => 'failed',
            'processed_files' => 1,
            'success_files' => 0,
            'failed_files' => 1,
        ]);
        $this->assertDatabaseHas('telegram_filestore_restore_files', [
            'id' => 701,
            'status' => 'failed',
        ]);
    }

    public function test_command_logs_batch_and_session_pauses_while_processing_multiple_sessions(): void
    {
        DB::table('telegram_filestore_sessions')->insert([
            [
                'id' => 70,
                'chat_id' => 7702694790,
                'public_token' => 'filestoebot_pause_batch_one',
                'source_token' => null,
                'status' => 'closed',
                'total_files' => 11,
                'created_at' => now(),
                'closed_at' => now(),
            ],
            [
                'id' => 71,
                'chat_id' => 7702694790,
                'public_token' => 'filestoebot_pause_batch_two',
                'source_token' => null,
                'status' => 'closed',
                'total_files' => 1,
                'created_at' => now(),
                'closed_at' => now(),
            ],
        ]);

        $sourceFiles = [];
        for ($index = 0; $index < 11; $index++) {
            $sourceFiles[] = [
                'id' => 7000 + $index,
                'session_id' => 70,
                'chat_id' => 7702694790,
                'message_id' => 8000 + $index,
                'file_id' => 'PAUSE-BATCH-FILE-' . $index,
                'file_unique_id' => 'PAUSE-BATCH-UNIQ-' . $index,
                'source_token' => null,
                'file_name' => 'pause-' . $index . '.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 10 + $index,
                'file_type' => 'document',
                'raw_payload' => json_encode(['message_id' => 8000 + $index], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ];
        }
        $sourceFiles[] = [
            'id' => 7100,
            'session_id' => 71,
            'chat_id' => 7702694790,
            'message_id' => 8100,
            'file_id' => 'PAUSE-SECOND-SESSION-FILE',
            'file_unique_id' => 'PAUSE-SECOND-SESSION-UNIQ',
            'source_token' => null,
            'file_name' => 'pause-second-session.bin',
            'mime_type' => 'application/octet-stream',
            'file_size' => 99,
            'file_type' => 'document',
            'raw_payload' => json_encode(['message_id' => 8100], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ];
        DB::table('telegram_filestore_files')->insert($sourceFiles);

        $pendingUpdates = [];
        $nextUpdateId = 900;
        $forwardedMessageId = 99000;

        Http::fake(function ($request) use (&$pendingUpdates, &$nextUpdateId, &$forwardedMessageId) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/botrestore-token/getUpdates')) {
                $offset = (int) ($request['offset'] ?? 0);

                if ($offset <= 0) {
                    return Http::response([
                        'ok' => true,
                        'result' => [
                            [
                                'update_id' => 500,
                                'message' => [
                                    'message_id' => 90,
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

                if ($pendingUpdates !== []) {
                    return Http::response([
                        'ok' => true,
                        'result' => [array_shift($pendingUpdates)],
                    ], 200);
                }

                return Http::response([
                    'ok' => true,
                    'result' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/forward-messages') {
                $sourceMessageId = (int) ((array) $request['message_ids'])[0];
                $forwardedMessageId++;
                $nextUpdateId++;

                $pendingUpdates[] = [
                    'update_id' => $nextUpdateId,
                    'message' => [
                        'message_id' => $forwardedMessageId + 1000,
                        'chat' => [
                            'id' => 8491679630,
                            'type' => 'private',
                        ],
                        'document' => [
                            'file_id' => 'TARGET-FILE-' . $sourceMessageId,
                            'file_unique_id' => 'TARGET-UNIQ-' . $sourceMessageId,
                            'file_name' => 'target-' . $sourceMessageId . '.bin',
                            'mime_type' => 'application/octet-stream',
                            'file_size' => 123,
                        ],
                    ],
                ];

                return Http::response([
                    'status' => 'ok',
                    'forwarded_message_ids' => [$forwardedMessageId],
                    'missing_message_ids' => [],
                    'unforwardable_message_ids' => [],
                ], 200);
            }

            if ($request->url() === 'http://127.0.0.1:8001/bots/delete-messages') {
                return Http::response([
                    'status' => 'ok',
                    'deleted_count' => count((array) $request['message_ids']),
                    'undeleted_message_ids' => [],
                ], 200);
            }

            return Http::response([
                'ok' => false,
                'status' => 'unexpected',
                'url' => $request->url(),
            ], 500);
        });

        $this->artisan('filestore:restore-to-bot --all --limit=12 --base-uri=http://127.0.0.1:8001 --target-bot-token=restore-token --target-bot-username=file_backup_restore_bot --worker-env=tests/Fixtures/missing-worker.env')
            ->expectsOutputToContain('pause 1.5s after 10 files')
            ->expectsOutputToContain('pause 3.5s before next restore session')
            ->expectsOutputToContain('synced=12')
            ->assertExitCode(0);
    }
}

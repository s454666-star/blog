<?php

namespace Tests\Feature;

use App\Services\TelegramFilestoreTokenBridgeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DispatchTokenScanItemsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('dialogues');
        Schema::dropIfExists('token_scan_items');
        Schema::create('token_scan_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('header_id')->nullable();
            $table->string('token', 255);
            $table->timestamps();
        });

        Schema::create('dialogues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('text', 255);
            $table->boolean('is_read')->default(false);
            $table->boolean('is_sync')->default(false);
            $table->dateTime('created_at')->nullable();
        });
    }

    private function createFilestoreBridgeTables(): void
    {
        Schema::dropIfExists('telegram_filestore_files');
        Schema::dropIfExists('telegram_filestore_sessions');

        Schema::create('telegram_filestore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('source_token')->nullable();
            $table->string('public_token')->nullable();
            $table->string('status')->default('closed');
            $table->unsignedInteger('total_files')->default(0);
        });

        Schema::create('telegram_filestore_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('file_id')->nullable();
        });
    }

    public function test_dispatch_uses_send_then_pagination_only_for_vip_like_bot(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'showfilesbot_abc123',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake([
            'http://127.0.0.1:8000/bots/send' => Http::response(['status' => 'ok'], 200),
            'http://127.0.0.1:8000/bots/run-all-pages-by-bot' => Http::response([
                'status' => 'ok',
                'reason' => 'completion message detected',
                'files_unique_count' => 1,
                'files_total_bytes' => 123,
                'latest_message' => [
                    'kind' => 'completion',
                    'text_preview' => 'done',
                    'has_buttons' => false,
                    'page_info' => [],
                ],
                'timeline' => [],
                'debug' => [],
                'page_state' => [],
            ], 200),
        ]);

        $this->artisan('tg:dispatch-token-scan-items')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send'
                && $request['bot_username'] === 'vipfiles2bot'
                && $request['text'] === 'showfilesbot_abc123'
                && $request['clear_previous_replies'] === true;
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot'
                && $request['bot_username'] === 'vipfiles2bot'
                && $request['clear_previous_replies'] === false
                && !isset($request['text']);
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), '/bots/send-and-run-all-pages');
        });
    }

    public function test_dispatch_uses_send_and_run_all_pages_for_mtfxq_bot(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('dialogues')->insert([
            'chat_id' => 7702694790,
            'message_id' => 1,
            'text' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'is_read' => 1,
            'is_sync' => 0,
            'created_at' => now(),
        ]);

        Http::fake([
            'http://127.0.0.1:8000/bots/send-and-run-all-pages' => Http::response([
                'status' => 'ok',
                'reason' => 'reached total items after final page click; stop',
                'files_unique_count' => 61,
                'files_total_bytes' => 123,
                'latest_message' => [
                    'kind' => 'state',
                    'text_preview' => '✅ 第 **7**/7 页 📀全部类型',
                    'has_buttons' => false,
                    'page_info' => [
                        'current_page' => 7,
                        'total_pages' => 7,
                    ],
                ],
                'timeline' => [
                    [
                        'step' => 0,
                        'status' => 'bootstrap_clicked_anytime',
                        'clicked_text' => '📀获取全部(61)',
                    ],
                    [
                        'step' => 1,
                        'status' => 'clicked',
                        'clicked' => '2',
                    ],
                    [
                        'step' => 7,
                        'status' => 'done',
                        'reason' => 'reached total items after final page click; stop',
                    ],
                ],
                'debug' => [],
                'page_state' => [
                    'did_bootstrap_click' => true,
                    'did_any_pagination_click' => true,
                    'last_clicked_page' => 7,
                ],
            ], 200),
        ]);

        $this->artisan('tg:dispatch-token-scan-items')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'text' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'is_sync' => 1,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send-and-run-all-pages'
                && $request['bot_username'] === 'mtfxqbot'
                && $request['text'] === 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2'
                && $request['clear_previous_replies'] === true
                && $request['download_after_done'] === false
                && $request['wait_download_completion'] === false;
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), '/bots/send')
                && !str_contains($request->url(), '/bots/send-and-run-all-pages');
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), '/bots/run-all-pages-by-bot');
        });
    }

    public function test_dispatch_sends_group_notice_after_successful_filestore_sync(): void
    {
        $this->createFilestoreBridgeTables();

        config()->set('telegram.filestore_bot_token', 'test-filestore-bot-token');
        config()->set('telegram.filestore_sync_bot_username', 'filestoebot');

        $this->app->instance(TelegramFilestoreTokenBridgeService::class, new class
        {
            public function sync(string $token, string $baseUri, string $botApi, int $sentMessageId, bool $deleteSourceMessages): array
            {
                return [
                    'ok' => true,
                    'status' => 'synced',
                    'session_id' => 9527,
                    'public_token' => 'filestoebot_13P_notice_test',
                    'observed_files' => 61,
                    'observed_total_bytes' => 123,
                    'stored_files' => 61,
                    'skipped_files' => 0,
                    'summary' => 'filestore synced target=@filestoebot session_id=9527 public_token=filestoebot_13P_notice_test forwarded=61 stored=61 skipped=0 deleted_forwarded=yes',
                ];
            }
        });

        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_13P_notice_sync001',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('dialogues')->insert([
            'chat_id' => 7702694790,
            'message_id' => 2,
            'text' => 'mtfxqbot_13P_notice_sync001',
            'is_read' => 1,
            'is_sync' => 0,
            'created_at' => now(),
        ]);

        Http::fake([
            'http://127.0.0.1:8000/bots/send-and-run-all-pages' => Http::response([
                'status' => 'ok',
                'sent_message_id' => 8811,
                'reason' => 'reached total items after final page click; stop',
                'files_unique_count' => 61,
                'files_total_bytes' => 123,
                'latest_message' => [
                    'kind' => 'state',
                    'text_preview' => '✅ 第 **7**/7 页 📀全部类型',
                    'has_buttons' => false,
                    'page_info' => [
                        'current_page' => 7,
                        'total_pages' => 7,
                    ],
                ],
                'timeline' => [],
                'debug' => [],
                'page_state' => [
                    'did_bootstrap_click' => true,
                    'did_any_pagination_click' => true,
                    'last_clicked_page' => 7,
                ],
            ], 200),
            'https://api.telegram.org/bottest-filestore-bot-token/sendMessage' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 777,
                ],
            ], 200),
        ]);

        $this->artisan('tg:dispatch-token-scan-items')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'text' => 'mtfxqbot_13P_notice_sync001',
            'is_sync' => 1,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/bottest-filestore-bot-token/sendMessage'
                && (string) $request['chat_id'] === '-1003772011392'
                && (string) $request['text'] === 'mtfxqbot_13P_notice_sync001  已收錄至機器人 @filestoebot';
        });
    }

    public function test_dispatch_moves_explicit_mtfxq_not_found_token_into_dialogues_and_deletes_queue_row(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_1V_notfound0008',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('dialogues')->insert([
            'chat_id' => 7702694790,
            'message_id' => 2,
            'text' => 'mtfxqbot_1V_notfound0008',
            'is_read' => 1,
            'is_sync' => 0,
            'created_at' => now(),
        ]);

        Http::fake([
            'http://127.0.0.1:8000/bots/send-and-run-all-pages' => Http::response([
                'status' => 'ok',
                'reason' => 'not found',
                'files_unique_count' => 0,
                'files_total_bytes' => 0,
                'latest_message' => [
                    'kind' => 'other',
                    'text_preview' => '💔抱歉，未找到可解析内容。本机器人只能解析 mtfxq_ 代码，满血版免费领取需达到分享活跃度 /start，免分享VIP：/pay',
                    'has_buttons' => false,
                    'page_info' => [],
                ],
                'timeline' => [],
                'debug' => [],
                'page_state' => [],
            ], 200),
        ]);

        $this->artisan('tg:dispatch-token-scan-items')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'text' => 'mtfxqbot_1V_notfound0008',
            'is_sync' => 1,
        ]);
    }

    public function test_dispatch_keeps_queue_row_for_non_explicit_not_found_message(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_1V_notfound0009',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('dialogues')->insert([
            'chat_id' => 7702694790,
            'message_id' => 3,
            'text' => 'mtfxqbot_1V_notfound0009',
            'is_read' => 1,
            'is_sync' => 0,
            'created_at' => now(),
        ]);

        Http::fake([
            'http://127.0.0.1:8000/bots/send-and-run-all-pages' => Http::response([
                'status' => 'ok',
                'reason' => 'not found',
                'files_unique_count' => 0,
                'files_total_bytes' => 0,
                'latest_message' => [
                    'kind' => 'other',
                    'text_preview' => '💔抱歉，未找到可解析内容。',
                    'has_buttons' => false,
                    'page_info' => [],
                ],
                'timeline' => [],
                'debug' => [],
                'page_state' => [],
            ], 200),
        ]);

        $this->artisan('tg:dispatch-token-scan-items')
            ->assertExitCode(0);

        $this->assertDatabaseHas('token_scan_items', [
            'id' => $itemId,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'text' => 'mtfxqbot_1V_notfound0009',
            'is_sync' => 0,
        ]);
    }

    public function test_dispatch_solves_mtfxq_image_captcha_with_openai_number_only(): void
    {
        putenv('GPT_API_KEY=test-openai-key');
        $_ENV['GPT_API_KEY'] = 'test-openai-key';
        $_SERVER['GPT_API_KEY'] = 'test-openai-key';

        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_41P_36V_E1J7z7H4G6X8o0i8s5w7',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        DB::table('dialogues')->insert([
            'chat_id' => 7702694790,
            'message_id' => 3,
            'text' => 'mtfxqbot_41P_36V_E1J7z7H4G6X8o0i8s5w7',
            'is_read' => 1,
            'is_sync' => 0,
            'created_at' => now(),
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'mtfxq_');
        $imagePath = $tempFile . '.jpg';
        @rename($tempFile, $imagePath);
        file_put_contents($imagePath, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAVEQEBAAAAAAAAAAAAAAAAAAABAP/aAAwDAQACEAMQAAAB6AAAAP/EABQQAQAAAAAAAAAAAAAAAAAAACD/2gAIAQEAAT8Af//EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQIBAT8Af//EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQMBAT8Af//Z'));

        $sendAndRunCount = 0;
        $captchaAnswered = false;
        $openAiCount = 0;

        try {
            Http::fake(function ($request) use (&$sendAndRunCount, &$captchaAnswered, &$openAiCount, $imagePath) {
                $url = $request->url();
                $botUsername = (string) ($request['bot_username'] ?? '');

                if ($url === 'http://127.0.0.1:8000/bots/send-and-run-all-pages' && $botUsername === 'mtfxqbot') {
                    $sendAndRunCount++;

                    if ($sendAndRunCount === 1) {
                        return Http::response([
                            'status' => 'ok',
                            'sent_message_id' => 473200,
                            'reason' => 'captcha required',
                            'files_unique_count' => 0,
                            'files_total_bytes' => 0,
                            'latest_message' => [
                                'kind' => 'other',
                                'text_preview' => 'To continue, please count how many **🐷 Pig** are shown in the image: 请计算图中**🐷 Pig**的数量以继续操作：',
                                'has_buttons' => true,
                                'page_info' => [],
                            ],
                            'timeline' => [],
                            'debug' => [],
                            'page_state' => [],
                        ], 200);
                    }

                    return Http::response([
                        'status' => 'ok',
                        'sent_message_id' => 473200,
                        'reason' => 'reached total items after final page click; stop',
                        'files_unique_count' => 4,
                        'files_total_bytes' => 1024,
                        'latest_message' => [
                            'kind' => 'state',
                            'text_preview' => '✅共找到 **4** 个媒体',
                            'has_buttons' => false,
                            'page_info' => [],
                        ],
                        'timeline' => [],
                        'debug' => [],
                        'page_state' => [],
                    ], 200);
                }

                if (str_starts_with($url, 'http://127.0.0.1:8000/bots/replies')) {
                    if (!$captchaAnswered) {
                        return Http::response([
                            [
                                'bot_username' => 'mtfxqbot',
                                'message_id' => 473115,
                                'text' => 'To continue, please count how many **🐷 Pig** are shown in the image:' . "\n" . '请计算图中**🐷 Pig**的数量以继续操作：',
                                'buttons' => [
                                    ['text' => '2'],
                                    ['text' => '4'],
                                    ['text' => '5'],
                                    ['text' => '3'],
                                ],
                                'file' => [
                                    'file_type' => 'photo',
                                    'mime_type' => 'image/jpeg',
                                ],
                            ],
                        ], 200);
                    }

                    return Http::response([], 200);
                }

                if ($url === 'http://127.0.0.1:8000/bots/download-message-media') {
                    return Http::response([
                        'status' => 'ok',
                        'saved_path' => $imagePath,
                    ], 200);
                }

                if ($url === 'https://api.openai.com/v1/chat/completions') {
                    $openAiCount++;

                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'content' => '4',
                                ],
                            ],
                        ],
                    ], 200);
                }

                if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'mtfxqbot') {
                    $captchaAnswered = true;

                    return Http::response([
                        'status' => 'ok',
                        'reason' => 'clicked matching button',
                        'button_clicked' => true,
                        'clicked_button_text' => '4',
                        'files_unique_count' => 0,
                        'files_total_bytes' => 0,
                        'latest_message' => [
                            'kind' => 'state',
                            'text_preview' => 'captcha solved',
                            'has_buttons' => false,
                            'buttons_text' => [],
                        ],
                        'timeline' => [],
                        'debug' => [],
                    ], 200);
                }

                return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
            });

            $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(0);
        } finally {
            @unlink($imagePath);
        }

        $this->assertSame(2, $sendAndRunCount);
        $this->assertSame(1, $openAiCount);
        $this->assertTrue($captchaAnswered);
        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'text' => 'mtfxqbot_41P_36V_E1J7z7H4G6X8o0i8s5w7',
            'is_sync' => 1,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/download-message-media'
                && $request['bot_username'] === 'mtfxqbot'
                && $request['message_id'] === 473115;
        });

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'https://api.openai.com/v1/chat/completions') {
                return false;
            }

            $payload = $request->data();
            $systemPrompt = (string) ($payload['messages'][0]['content'] ?? '');
            $userPrompt = (string) ($payload['messages'][1]['content'][0]['text'] ?? '');
            $imageUrl = (string) ($payload['messages'][1]['content'][1]['image_url']['url'] ?? '');

            return str_contains($systemPrompt, 'reply with digits only')
                && str_contains($userPrompt, 'Return only the number as plain digits')
                && str_starts_with($imageUrl, 'data:image/jpeg;base64,');
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'mtfxqbot'
                && $request['sent_message_id'] === 473115
                && $request['button_keywords'] === ['4'];
        });
    }

    public function test_dispatch_refreshes_previous_mtfxq_captcha_before_solving_new_image(): void
    {
        putenv('GPT_API_KEY=test-openai-key');
        $_ENV['GPT_API_KEY'] = 'test-openai-key';
        $_SERVER['GPT_API_KEY'] = 'test-openai-key';

        DB::table('token_scan_items')->insert([
            'token' => 'mtfxqbot_refresh_case',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $tempFile = tempnam(sys_get_temp_dir(), 'mtfxq_');
        $imagePath = $tempFile . '.jpg';
        @rename($tempFile, $imagePath);
        file_put_contents($imagePath, base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAVEQEBAAAAAAAAAAAAAAAAAAABAP/aAAwDAQACEAMQAAAB6AAAAP/EABQQAQAAAAAAAAAAAAAAAAAAACD/2gAIAQEAAT8Af//EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQIBAT8Af//EABQRAQAAAAAAAAAAAAAAAAAAACD/2gAIAQMBAT8Af//Z'));

        $sendAndRunCount = 0;
        $staleCaptchaClicked = false;
        $newCaptchaSolved = false;

        try {
            Http::fake(function ($request) use (&$sendAndRunCount, &$staleCaptchaClicked, &$newCaptchaSolved, $imagePath) {
                $url = $request->url();
                $botUsername = (string) ($request['bot_username'] ?? '');

                if ($url === 'http://127.0.0.1:8000/bots/send-and-run-all-pages' && $botUsername === 'mtfxqbot') {
                    $sendAndRunCount++;

                    if ($sendAndRunCount === 1) {
                        return Http::response([
                            'status' => 'ok',
                            'sent_message_id' => 473250,
                            'reason' => 'no_click',
                            'files_unique_count' => 0,
                            'files_total_bytes' => 0,
                            'latest_message' => [
                                'kind' => 'other',
                                'text_preview' => "请先完成验证码，再发送其他消息。\nPlease complete the captcha first before sending other messages.",
                                'has_buttons' => true,
                                'page_info' => [],
                            ],
                            'timeline' => [],
                            'debug' => [],
                            'page_state' => [],
                        ], 200);
                    }

                    return Http::response([
                        'status' => 'ok',
                        'sent_message_id' => 473250,
                        'reason' => 'reached total items after final page click; stop',
                        'files_unique_count' => 2,
                        'files_total_bytes' => 2048,
                        'latest_message' => [
                            'kind' => 'state',
                            'text_preview' => '✅共找到 **2** 个媒体',
                            'has_buttons' => false,
                            'page_info' => [],
                        ],
                        'timeline' => [],
                        'debug' => [],
                        'page_state' => [],
                    ], 200);
                }

                if (str_starts_with($url, 'http://127.0.0.1:8000/bots/replies')) {
                    if (!$staleCaptchaClicked) {
                        return Http::response([
                            [
                                'bot_username' => 'mtfxqbot',
                                'message_id' => 473121,
                                'text' => "请先完成验证码，再发送其他消息。\nPlease complete the captcha first before sending other messages.",
                                'buttons' => [
                                    ['text' => '重新生成验证码'],
                                ],
                            ],
                            [
                                'bot_username' => 'mtfxqbot',
                                'message_id' => 473115,
                                'text' => 'To continue, please count how many **🐷 Pig** are shown in the image:' . "\n" . '请计算图中**🐷 Pig**的数量以继续操作：',
                                'buttons' => [
                                    ['text' => '2'],
                                    ['text' => '4'],
                                    ['text' => '5'],
                                ],
                                'file' => [
                                    'file_type' => 'photo',
                                    'mime_type' => 'image/jpeg',
                                ],
                            ],
                        ], 200);
                    }

                    if (!$newCaptchaSolved) {
                        return Http::response([
                            [
                                'bot_username' => 'mtfxqbot',
                                'message_id' => 473130,
                                'text' => 'To continue, please count how many **🐷 Pig** are shown in the image:' . "\n" . '请计算图中**🐷 Pig**的数量以继续操作：',
                                'buttons' => [
                                    ['text' => '1'],
                                    ['text' => '4'],
                                    ['text' => '6'],
                                ],
                                'file' => [
                                    'file_type' => 'photo',
                                    'mime_type' => 'image/jpeg',
                                ],
                            ],
                        ], 200);
                    }

                    return Http::response([], 200);
                }

                if ($url === 'http://127.0.0.1:8000/bots/download-message-media') {
                    return Http::response([
                        'status' => 'ok',
                        'saved_path' => $imagePath,
                    ], 200);
                }

                if ($url === 'https://api.openai.com/v1/chat/completions') {
                    return Http::response([
                        'choices' => [
                            [
                                'message' => [
                                    'content' => '4',
                                ],
                            ],
                        ],
                    ], 200);
                }

                if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'mtfxqbot') {
                    if (($request['sent_message_id'] ?? 0) === 473115) {
                        $staleCaptchaClicked = true;

                        return Http::response([
                            'status' => 'ok',
                            'reason' => 'clicked matching button',
                            'button_clicked' => true,
                            'clicked_button_text' => '2',
                            'timeline' => [],
                            'debug' => [],
                        ], 200);
                    }

                    if (($request['sent_message_id'] ?? 0) === 473130) {
                        $newCaptchaSolved = true;

                        return Http::response([
                            'status' => 'ok',
                            'reason' => 'clicked matching button',
                            'button_clicked' => true,
                            'clicked_button_text' => '4',
                            'timeline' => [],
                            'debug' => [],
                        ], 200);
                    }
                }

                return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
            });

            $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(0);
        } finally {
            @unlink($imagePath);
        }

        $this->assertSame(2, $sendAndRunCount);
        $this->assertTrue($staleCaptchaClicked);
        $this->assertTrue($newCaptchaSolved);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'mtfxqbot'
                && $request['sent_message_id'] === 473115
                && $request['button_keywords'] === ['2'];
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'mtfxqbot'
                && $request['sent_message_id'] === 473130
                && $request['button_keywords'] === ['4'];
        });
    }

    public function test_dispatch_stops_early_when_mtfxq_denies_after_too_many_captcha_failures(): void
    {
        $secondId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_should_not_run',
            'created_at' => now()->subSecond(),
            'updated_at' => null,
        ]);

        $firstId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_denied_case',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake(function ($request) {
            if ($request->url() !== 'http://127.0.0.1:8000/bots/send-and-run-all-pages') {
                return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
            }

            if (($request['text'] ?? '') !== 'mtfxqbot_denied_case') {
                return Http::response(['status' => 'fail', 'reason' => 'should_not_run_next_token'], 500);
            }

            return Http::response([
                'status' => 'ok',
                'sent_message_id' => 474000,
                'reason' => 'service denied',
                'files_unique_count' => 0,
                'files_total_bytes' => 0,
                'latest_message' => [
                    'kind' => 'other',
                    'text_preview' => '由于验证码失败次数过多，服务已被拒绝。请在 119m 38s 后重试。' . "\n" . 'Service currently denied due to too many failed captcha attempts. Please try again after 119m 38s.',
                    'has_buttons' => false,
                    'page_info' => [],
                ],
                'timeline' => [],
                'debug' => [],
                'page_state' => [],
            ], 200);
        });

        $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(3);

        $this->assertDatabaseHas('token_scan_items', [
            'id' => $firstId,
        ]);
        $this->assertDatabaseHas('token_scan_items', [
            'id' => $secondId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send-and-run-all-pages'
                && $request['text'] === 'mtfxqbot_denied_case';
        });

        Http::assertNotSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send-and-run-all-pages'
                && ($request['text'] ?? '') === 'mtfxqbot_should_not_run';
        });
    }

    public function test_dispatch_uses_send_then_pagination_only_for_messenger_bot(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'Messengercode_abc123',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake([
            'http://127.0.0.1:8000/bots/send' => Http::response(['status' => 'ok'], 200),
            'http://127.0.0.1:8000/bots/run-all-pages-by-bot' => Http::response([
                'status' => 'ok',
                'reason' => 'completion message detected',
                'files_unique_count' => 0,
                'files_total_bytes' => 0,
                'latest_message' => [
                    'kind' => 'completion',
                    'text_preview' => '完成',
                    'has_buttons' => false,
                    'page_info' => [],
                ],
                'timeline' => [],
                'debug' => [],
                'page_state' => [],
            ], 200),
        ]);

        $this->artisan('tg:dispatch-token-scan-items')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send'
                && $request['bot_username'] === 'MessengerCode_bot'
                && $request['text'] === 'Messengercode_abc123'
                && $request['clear_previous_replies'] === true;
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot'
                && $request['bot_username'] === 'MessengerCode_bot'
                && $request['clear_previous_replies'] === false
                && !isset($request['text']);
        });

        Http::assertNotSent(function ($request): bool {
            return str_contains($request->url(), '/bots/send-and-run-all-pages');
        });
    }

    public function test_dispatch_sends_qqfile_tokens_to_qqfile_bot_and_clicks_push_all(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'QQfile_bot:14120_108172_755-39P_10V',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'QQfile_bot') {
                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 321,
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'QQfile_bot') {
                return Http::response([
                    'status' => 'ok',
                    'reason' => 'clicked matching button',
                    'button_clicked' => true,
                    'clicked_button_text' => '推送全部',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'latest_message' => [
                        'kind' => 'state',
                        'text_preview' => '已點擊',
                        'has_buttons' => false,
                        'buttons_text' => [],
                    ],
                    'timeline' => [
                        ['step' => 0, 'status' => 'clicked', 'clicked_button_text' => '推送全部'],
                    ],
                    'debug' => [],
                    'outcome' => [
                        'run_completed' => true,
                    ],
                ], 200);
            }

            if (str_starts_with($url, 'http://127.0.0.1:8000/bots/replies')) {
                return Http::response([
                    [
                        'bot_username' => 'QQfile_bot',
                        'message_id' => 322,
                        'text' => '文件获取完毕，文件总数 3',
                        'buttons' => [],
                    ],
                ], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send'
                && $request['bot_username'] === 'QQfile_bot'
                && $request['text'] === 'QQfile_bot:14120_108172_755-39P_10V';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'QQfile_bot'
                && $request['sent_message_id'] === 321
                && $request['button_keywords'] === ['推送全部'];
        });

        Http::assertNotSent(function ($request): bool {
            $botUsername = (string) ($request['bot_username'] ?? '');
            return in_array($botUsername, ['newjmqbot', 'Showfiles6bot'], true);
        });
    }

    public function test_dispatch_solves_qqfile_verification_with_openai_then_resends_token(): void
    {
        putenv('GPT_API_KEY=test-openai-key');
        $_ENV['GPT_API_KEY'] = 'test-openai-key';
        $_SERVER['GPT_API_KEY'] = 'test-openai-key';

        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'QQfile_bot:17013_137025_248-4V',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $sendCount = 0;
        $openAiCount = 0;
        $verificationAnswered = false;
        $fallbackClickedAfterResend = false;

        Http::fake(function ($request) use (&$sendCount, &$openAiCount, &$verificationAnswered, &$fallbackClickedAfterResend) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'https://api.openai.com/v1/chat/completions') {
                $openAiCount++;

                return Http::response([
                    'choices' => [
                        [
                            'message' => [
                                'content' => '5️⃣',
                            ],
                        ],
                    ],
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'QQfile_bot') {
                $sendCount++;

                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => $sendCount === 1 ? 321 : 654,
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'QQfile_bot') {
                $buttonKeywords = $request['button_keywords'] ?? [];

                if ($buttonKeywords === ['推送全部'] && !$verificationAnswered) {
                    return Http::response([
                        'status' => 'fail',
                        'reason' => 'no matching button found',
                        'files_unique_count' => 0,
                        'files_total_bytes' => 0,
                        'button_clicked' => false,
                        'clicked_button_text' => '',
                        'latest_message' => [
                            'kind' => 'state',
                            'text_preview' => '--触发风控验证-- 4️⃣ x 5️⃣ = ❓ 请选择正确的计算结果',
                            'has_buttons' => true,
                            'buttons_text' => ['1️⃣0️⃣', '2️⃣0️⃣', '2️⃣5️⃣'],
                        ],
                        'timeline' => [
                            ['step' => 0, 'status' => 'fail', 'reason' => 'no matching button found'],
                        ],
                        'debug' => [],
                    ], 200);
                }

                if ($buttonKeywords === ['5️⃣']) {
                    $verificationAnswered = true;

                    return Http::response([
                        'status' => 'ok',
                        'reason' => 'clicked matching button',
                        'button_clicked' => true,
                        'clicked_button_text' => '5️⃣',
                        'files_unique_count' => 0,
                        'files_total_bytes' => 0,
                        'latest_message' => [
                            'kind' => 'state',
                            'text_preview' => '验证通过',
                            'has_buttons' => false,
                            'buttons_text' => [],
                        ],
                        'timeline' => [
                            ['step' => 0, 'status' => 'clicked', 'clicked_button_text' => '5️⃣'],
                        ],
                        'debug' => [],
                    ], 200);
                }

                if ($buttonKeywords === ['查看全部文件'] && $verificationAnswered) {
                    $fallbackClickedAfterResend = true;

                    return Http::response([
                        'status' => 'ok',
                        'reason' => 'clicked matching button',
                        'button_clicked' => true,
                        'clicked_button_text' => '📁查看全部文件',
                        'files_unique_count' => 0,
                        'files_total_bytes' => 0,
                        'latest_message' => [
                            'kind' => 'state',
                            'text_preview' => '已點擊',
                            'has_buttons' => false,
                            'buttons_text' => [],
                        ],
                        'timeline' => [
                            ['step' => 0, 'status' => 'clicked', 'clicked_button_text' => '📁查看全部文件'],
                        ],
                        'debug' => [],
                    ], 200);
                }
            }

            if (str_starts_with($url, 'http://127.0.0.1:8000/bots/replies')) {
                $minMessageId = (int) ($request->data()['min_message_id'] ?? 0);

                if (!$verificationAnswered && $minMessageId === 321) {
                    return Http::response([
                        [
                            'bot_username' => 'QQfile_bot',
                            'message_id' => 322,
                            'text' => '--触发风控验证-- 5️⃣ x 1️⃣ = ❓ 请选择正确的计算结果',
                            'buttons' => [
                                ['text' => '4️⃣'],
                                ['text' => '5️⃣'],
                                ['text' => '6️⃣'],
                            ],
                        ],
                    ], 200);
                }

                if ($verificationAnswered && !$fallbackClickedAfterResend && $minMessageId === 654) {
                    return Http::response([
                        [
                            'bot_username' => 'QQfile_bot',
                            'message_id' => 655,
                            'text' => "**资源详情**\n资源编号：17167_115106_006\n资源文件：🎬2 \n资源热度：1\n\n分享代码：`QQfile_bot:17167_115106_006-2V`\n分享链接：`https://t.me/QQfile_bot?start=17167_115106_006`\n成功分享给好友得0.0008USDT/次",
                            'buttons' => [
                                ['text' => '📁查看全部文件'],
                                ['text' => '📀收藏文件码'],
                                ['text' => '👍1'],
                                ['text' => '👎0'],
                            ],
                        ],
                    ], 200);
                }

                if ($fallbackClickedAfterResend && $minMessageId === 654) {
                    return Http::response([
                        [
                            'bot_username' => 'QQfile_bot',
                            'message_id' => 656,
                            'text' => '文件获取完毕，文件总数 4',
                            'buttons' => [],
                        ],
                        [
                            'bot_username' => 'QQfile_bot',
                            'message_id' => 657,
                            'text' => "**资源详情**\n资源编号：17167_115106_006\n资源文件：🎬2 \n资源热度：1\n\n分享代码：`QQfile_bot:17167_115106_006-2V`\n分享链接：`https://t.me/QQfile_bot?start=17167_115106_006`\n成功分享给好友得0.0008USDT/次\n\n您已于2026-03-27 19:56:54解析过此资源",
                            'buttons' => [
                                ['text' => '📁查看全部文件'],
                                ['text' => '📀收藏文件码'],
                                ['text' => '👍1'],
                                ['text' => '👎0'],
                            ],
                        ],
                    ], 200);
                }

                return Http::response([], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(0);

        $this->assertSame(2, $sendCount);
        $this->assertSame(1, $openAiCount);
        $this->assertTrue($verificationAnswered);
        $this->assertTrue($fallbackClickedAfterResend);
        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/chat/completions';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'QQfile_bot'
                && $request['button_keywords'] === ['5️⃣'];
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'QQfile_bot'
                && $request['button_keywords'] === ['查看全部文件'];
        });
    }

    public function test_dispatch_falls_back_from_qqfile_to_yzfile_with_start_command(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'QQfile_bot:14191_108172_777-22P',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'QQfile_bot') {
                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 101,
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'QQfile_bot') {
                return Http::response([
                    'status' => 'fail',
                    'reason' => 'no matching button found',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'button_clicked' => false,
                    'clicked_button_text' => '',
                    'latest_message' => [
                        'kind' => 'other',
                        'text_preview' => '当前解码器未完成同步，请使用 yzfile_bot (https://t.me/yzfile_bot?start=14120_76302_755)  解码此资源或24小时后重试',
                        'has_buttons' => false,
                        'buttons_text' => [],
                    ],
                    'timeline' => [],
                    'debug' => [],
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'yzfile_bot') {
                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 202,
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'yzfile_bot') {
                return Http::response([
                    'status' => 'ok',
                    'reason' => 'clicked matching button',
                    'button_clicked' => true,
                    'clicked_button_text' => '推送全部',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'latest_message' => [
                        'kind' => 'state',
                        'text_preview' => '已點擊',
                        'has_buttons' => false,
                        'buttons_text' => [],
                    ],
                    'timeline' => [
                        ['step' => 0, 'status' => 'clicked', 'clicked_button_text' => '推送全部'],
                    ],
                    'debug' => [],
                    'completed' => true,
                    'outcome' => [
                        'run_completed' => true,
                    ],
                ], 200);
            }

            if (str_starts_with($url, 'http://127.0.0.1:8000/bots/replies')) {
                return Http::response([
                    [
                        'bot_username' => 'yzfile_bot',
                        'message_id' => 203,
                        'text' => '文件获取完毕，文件总数 2',
                        'buttons' => [],
                    ],
                ], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'QQfile_bot'
                && $request['sent_message_id'] === 101;
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send'
                && $request['bot_username'] === 'yzfile_bot'
                && $request['text'] === '/start 14120_76302_755';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/click-matching-button'
                && $request['bot_username'] === 'yzfile_bot'
                && $request['sent_message_id'] === 202;
        });
    }

    public function test_dispatch_retries_current_messenger_token_when_take_too_fast_message_is_observed(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'Messengercode_retry_after_take_too_fast',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $sendCount = 0;
        $runCount = 0;

        Http::fake(function ($request) use (&$sendCount, &$runCount) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'MessengerCode_bot') {
                $sendCount++;

                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 500 + $sendCount,
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot' && $botUsername === 'MessengerCode_bot') {
                $runCount++;

                if ($runCount === 1) {
                    return Http::response([
                        'status' => 'ok',
                        'reason' => 'rate limited',
                        'files_unique_count' => 0,
                        'files_total_bytes' => 0,
                        'latest_message' => [
                            'kind' => 'other',
                            'text_preview' => '取件太快了，1 秒后再试。',
                            'has_buttons' => false,
                            'page_info' => [],
                        ],
                        'timeline' => [],
                        'debug' => [],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ok',
                    'reason' => 'completion message detected',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'latest_message' => [
                        'kind' => 'completion',
                        'text_preview' => '完成',
                        'has_buttons' => false,
                        'page_info' => [],
                    ],
                    'timeline' => [],
                    'debug' => [],
                    'page_state' => [],
                ], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items')->assertExitCode(0);

        $this->assertSame(2, $sendCount);
        $this->assertSame(2, $runCount);
        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);
    }

    public function test_dispatch_retries_current_unresolved_qq_token_inside_the_same_command(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'QQfile_bot:17051_63359_820-2V',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        $sendCount = 0;
        $clickCount = 0;

        Http::fake(function ($request) use (&$sendCount, &$clickCount) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'QQfile_bot') {
                $sendCount++;

                if ($sendCount === 1) {
                    return Http::response([
                        'status' => 'ok',
                    ], 200);
                }

                return Http::response([
                    'status' => 'ok',
                    'sent_message_id' => 321,
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'QQfile_bot') {
                $clickCount++;

                if ($clickCount === 1) {
                    return Http::response([
                        'status' => 'fail',
                        'reason' => 'no matching button found',
                        'files_unique_count' => 0,
                        'files_total_bytes' => 0,
                        'button_clicked' => false,
                        'clicked_button_text' => '',
                        'latest_message' => [
                            'kind' => 'other',
                            'text_preview' => '未找到可點擊的推送按鈕',
                            'has_buttons' => false,
                            'buttons_text' => [],
                        ],
                        'timeline' => [
                            ['step' => 0, 'status' => 'fail', 'reason' => 'no matching button found'],
                        ],
                        'debug' => [],
                    ], 200);
                }

                return Http::response([
                    'status' => 'ok',
                    'reason' => 'clicked matching button',
                    'button_clicked' => true,
                    'clicked_button_text' => '推送全部',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'latest_message' => [
                        'kind' => 'state',
                        'text_preview' => '已點擊',
                        'has_buttons' => false,
                        'buttons_text' => [],
                    ],
                    'timeline' => [
                        ['step' => 0, 'status' => 'clicked', 'clicked_button_text' => '推送全部'],
                    ],
                    'debug' => [],
                ], 200);
            }

            if (str_starts_with($url, 'http://127.0.0.1:8000/bots/replies')) {
                return Http::response([
                    [
                        'bot_username' => 'QQfile_bot',
                        'message_id' => 322,
                        'text' => '文件获取完毕，文件总数 3',
                        'buttons' => [],
                    ],
                ], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items --stopped-early-retry-delay=0')
            ->assertExitCode(0);

        $this->assertSame(2, $sendCount);
        $this->assertGreaterThanOrEqual(2, $clickCount);
        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);
    }

    public function test_dispatch_returns_retry_exit_code_when_it_stops_early_for_unresolved_qq_run(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'QQfile_bot:17051_63359_820-2V',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'QQfile_bot') {
                return Http::response([
                    'status' => 'ok',
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/click-matching-button' && $botUsername === 'QQfile_bot') {
                return Http::response([
                    'status' => 'fail',
                    'reason' => 'no matching button found',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'button_clicked' => false,
                    'clicked_button_text' => '',
                    'latest_message' => [
                        'kind' => 'other',
                        'text_preview' => '未找到可點擊的推送按鈕',
                        'has_buttons' => false,
                        'buttons_text' => [],
                    ],
                    'timeline' => [
                        ['step' => 0, 'status' => 'fail', 'reason' => 'no matching button found'],
                    ],
                    'debug' => [],
                ], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items --stopped-early-retry-delay=0 --stopped-early-max-retries=0')
            ->assertExitCode(3);

        $this->assertDatabaseHas('token_scan_items', [
            'id' => $itemId,
        ]);
    }
}

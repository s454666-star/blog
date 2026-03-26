<?php

namespace Tests\Feature;

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

        Schema::dropIfExists('token_scan_items');
        Schema::create('token_scan_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('header_id')->nullable();
            $table->string('token', 255);
            $table->timestamps();
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

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

    public function test_dispatch_uses_send_and_run_all_pages_for_mtfxq_bot(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'created_at' => now(),
            'updated_at' => null,
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

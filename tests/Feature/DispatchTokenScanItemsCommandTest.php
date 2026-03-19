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

    public function test_dispatch_falls_back_to_newjmq_when_vipfiles_returns_no_data(): void
    {
        $itemId = DB::table('token_scan_items')->insertGetId([
            'token' => 'newjmqbot_abc123',
            'created_at' => now(),
            'updated_at' => null,
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            $botUsername = (string) ($request['bot_username'] ?? '');

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'vipfiles2bot') {
                return Http::response(['status' => 'ok'], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot' && $botUsername === 'vipfiles2bot') {
                return Http::response([
                    'status' => 'fail',
                    'reason' => 'no callback state message found',
                    'files_unique_count' => 0,
                    'files_total_bytes' => 0,
                    'latest_message' => [],
                    'timeline' => [
                        ['step' => 0, 'status' => 'fail', 'reason' => 'no callback state message found'],
                    ],
                    'debug' => [],
                    'page_state' => [],
                ], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/send' && $botUsername === 'newjmqbot') {
                return Http::response(['status' => 'ok'], 200);
            }

            if ($url === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot' && $botUsername === 'newjmqbot') {
                return Http::response([
                    'status' => 'ok',
                    'reason' => 'completion message detected',
                    'files_unique_count' => 6,
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
                    'completed' => true,
                    'outcome' => [
                        'run_completed' => true,
                    ],
                ], 200);
            }

            return Http::response(['status' => 'fail', 'reason' => 'unexpected request'], 500);
        });

        $this->artisan('tg:dispatch-token-scan-items', [
            '--fallback-newjmqbot' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('token_scan_items', [
            'id' => $itemId,
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send'
                && $request['bot_username'] === 'vipfiles2bot'
                && $request['text'] === 'newjmqbot_abc123';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot'
                && $request['bot_username'] === 'vipfiles2bot';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/send'
                && $request['bot_username'] === 'newjmqbot'
                && $request['text'] === 'newjmqbot_abc123';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/bots/run-all-pages-by-bot'
                && $request['bot_username'] === 'newjmqbot';
        });
    }
}

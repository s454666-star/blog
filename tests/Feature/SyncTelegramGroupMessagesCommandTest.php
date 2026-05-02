<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncTelegramGroupMessagesCommandTest extends TestCase
{
    private string $originalDatabaseDefault;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this feature test.');
        }

        $this->originalDatabaseDefault = (string) config('database.default');

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Schema::dropAllTables();
        Schema::create('telegram_group_messages', function (Blueprint $table): void {
            $table->id();
            $table->longText('group_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('message_code', 128)->unique();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('telegram_group_messages');

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_sync_group_messages_fetches_pages_and_stores_message_code(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/groups?*' => Http::response([
                'items' => [[
                    'id' => 3338106820,
                    'title' => '董事会——结束 祈应援会',
                    'last_message_id' => 3,
                ]],
                'count' => 1,
            ]),
            'http://127.0.0.1:8001/groups/3338106820/0?*' => Http::response([
                'status' => 'ok',
                'items' => [
                    ['id' => 1, 'date' => '2026-05-02T15:36:50+08:00', 'text' => '第一筆'],
                    ['id' => 2, 'date' => '2026-05-02T15:36:51+08:00', 'text' => '第二筆'],
                ],
            ]),
            'http://127.0.0.1:8001/groups/3338106820/2?*' => Http::response([
                'status' => 'ok',
                'items' => [
                    ['id' => 3, 'date' => '2026-05-02T15:36:52+08:00', 'text' => '第三筆'],
                ],
            ]),
        ]);

        $this->artisan('tg:sync-group-messages', [
            '--batch-size' => 2,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('telegram_group_messages', 3);
        $this->assertDatabaseHas('telegram_group_messages', [
            'group_message' => '第三筆',
            'sent_at' => '2026-05-02 15:36:52',
            'message_code' => 'https://t.me/c/3338106820/3',
        ]);
    }

    public function test_sync_group_messages_resumes_from_stored_max_message_id(): void
    {
        DB::table('telegram_group_messages')->insert([
            'id' => 1,
            'group_message' => '舊訊息',
            'sent_at' => '2026-05-02 15:36:52',
            'message_code' => 'https://t.me/c/3338106820/3',
        ]);

        Http::fake([
            'http://127.0.0.1:8001/groups?*' => Http::response([
                'items' => [[
                    'id' => 3338106820,
                    'title' => '董事会——结束 祈应援会',
                    'last_message_id' => 4,
                ]],
                'count' => 1,
            ]),
            'http://127.0.0.1:8001/groups/3338106820/3?*' => Http::response([
                'status' => 'ok',
                'items' => [
                    ['id' => 4, 'date' => '2026-05-02T15:36:53+08:00', 'text' => '新訊息'],
                ],
            ]),
        ]);

        $this->artisan('tg:sync-group-messages')->assertExitCode(0);

        $this->assertDatabaseCount('telegram_group_messages', 2);
        $this->assertDatabaseHas('telegram_group_messages', [
            'group_message' => '新訊息',
            'message_code' => 'https://t.me/c/3338106820/4',
        ]);
    }

    public function test_sync_group_messages_can_start_from_date(): void
    {
        $messages = [
            ['id' => 1, 'date' => '2026-04-30T23:59:59+08:00', 'text' => '舊訊息'],
            ['id' => 10, 'date' => '2026-05-01T00:00:00+08:00', 'text' => '五月一日'],
            ['id' => 11, 'date' => '2026-05-02T15:36:52+08:00', 'text' => '五月二日'],
        ];

        Http::fake(function ($request) use ($messages) {
            $url = $request->url();
            $path = parse_url($url, PHP_URL_PATH);

            if ($path === '/groups') {
                return Http::response([
                    'items' => [[
                        'id' => 3338106820,
                        'title' => '董事会——结束 祈应援会',
                        'last_message_id' => 11,
                    ]],
                    'count' => 1,
                ]);
            }

            if (preg_match('#^/groups/3338106820/(\d+)$#', (string) $path, $matches) === 1) {
                $cursor = (int) $matches[1];
                $items = array_values(array_filter(
                    $messages,
                    fn (array $message): bool => (int) $message['id'] > $cursor
                ));

                return Http::response([
                    'status' => 'ok',
                    'items' => $items,
                ]);
            }

            return Http::response(['status' => 'fail'], 404);
        });

        $this->artisan('tg:sync-group-messages', [
            '--from-date' => '2026-05-01',
            '--batch-size' => 100,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('telegram_group_messages', 2);
        $this->assertDatabaseMissing('telegram_group_messages', [
            'message_code' => 'https://t.me/c/3338106820/1',
        ]);
        $this->assertDatabaseHas('telegram_group_messages', [
            'group_message' => '五月一日',
            'sent_at' => '2026-05-01 00:00:00',
            'message_code' => 'https://t.me/c/3338106820/10',
        ]);
    }
}

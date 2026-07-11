<?php

namespace Tests\Feature;

use App\Models\TelegramResourceCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessTelegramResourceCodesCommandTest extends TestCase
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
        config()->set('telegram.resource_codes', [
            'base_uris' => 'http://127.0.0.1:8001,http://127.0.0.1:8002,http://127.0.0.1:8003',
            'source_peer_ids' => '3779285711,2352070665',
            'target_peer_id' => 3967395258,
            'bot_username' => 'zyxfids_bot',
            'code_type' => 1,
            'initial_scan_limit' => 1000,
            'scan_batch_size' => 500,
            'loop_sleep_seconds' => 1,
            'request_timeout_seconds' => 30,
        ]);
        config()->set('cache.stores.telegram_resource_codes', [
            'driver' => 'array',
            'serialize' => false,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Cache::store('telegram_resource_codes')->flush();

        Schema::dropAllTables();
        Schema::create('telegram_resource_codes', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 40)->unique();
            $table->unsignedTinyInteger('code_type')->default(1);
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedBigInteger('source_peer_id')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedTinyInteger('processing_account')->nullable();
            $table->unsignedSmallInteger('forwarded_message_count')->default(0);
            $table->dateTime('available_at')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('telegram_resource_codes');
        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);
        parent::tearDown();
    }

    public function test_scan_stores_only_unique_codes_with_type_one_and_no_message_text(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $peerId = str_contains($path, '3779285711') ? 3779285711 : 2352070665;

            return Http::response([
                'status' => 'ok',
                'items' => [[
                    'id' => $peerId === 3779285711 ? 104800 : 19600,
                    'text' => implode(' ', [
                        '說明文字不應存入資料表',
                        '4dc6eb55ee68f197a332ca4802aaf14420f76d74',
                        '4DC6EB55EE68F197A332CA4802AAF14420F76D74',
                        'b34bcc9ed2f0c3b82b72dfd663b41e8ac1b248c0',
                        'not-a-code',
                    ]),
                ]],
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--scan-only' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('telegram_resource_codes', 2);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '4dc6eb55ee68f197a332ca4802aaf14420f76d74',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
        ]);
        $this->assertFalse(Schema::hasColumn('telegram_resource_codes', 'message_text'));
    }

    public function test_flood_wait_switches_to_next_account_and_completes_only_after_cleanup(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => '7b7d9f6480eb124b436ebb6aa26632357401a778',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            if (str_contains($url, ':8001/resource-codes/process')) {
                return Http::response([
                    'status' => 'flood_wait',
                    'reason' => 'flood_wait',
                    'wait_seconds' => 120,
                    'cleanup_complete' => true,
                ], 200);
            }

            if (str_contains($url, ':8002/resource-codes/process')) {
                return Http::response([
                    'status' => 'ok',
                    'forwarded_count' => 2,
                    'cleanup_complete' => true,
                ], 200);
            }

            return Http::response(['status' => 'error'], 500);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '7b7d9f6480eb124b436ebb6aa26632357401a778',
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'processing_account' => 2,
            'forwarded_message_count' => 2,
        ]);
    }
}

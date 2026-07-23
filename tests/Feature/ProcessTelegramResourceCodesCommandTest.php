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
            'source_topic_ids' => null,
            'target_peer_id' => 3967395258,
            'bot_username' => 'zyxfids_bot',
            'code_type' => 1,
            'processing_profiles' => null,
            'scan_code_types' => null,
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

        Schema::connection('sqlite')->create('telegram_resource_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 128)->unique();
            $table->unsignedTinyInteger('code_type')->default(1);
            $table->unsignedTinyInteger('status')->default(0);
            $table->unsignedTinyInteger('skip_reason')->nullable();
            $table->unsignedBigInteger('source_peer_id')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedTinyInteger('processing_account')->nullable();
            $table->unsignedSmallInteger('forwarded_message_count')->default(0);
            $table->unsignedSmallInteger('decoder_sent_count')->nullable();
            $table->unsignedSmallInteger('decoder_total_count')->nullable();
            $table->dateTime('available_at')->nullable();
            $table->dateTime('processing_started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('skipped_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            parent::tearDown();

            return;
        }

        Schema::connection('sqlite')->dropIfExists('telegram_resource_codes');
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
        $this->assertFalse(Schema::connection('sqlite')->hasColumn('telegram_resource_codes', 'message_text'));
    }

    public function test_scan_limits_a_forum_source_to_its_configured_topic(): void
    {
        config()->set('telegram.resource_codes.source_peer_ids', '2589355088');
        config()->set('telegram.resource_codes.source_topic_ids', '2589355088:11');

        Http::fake(function ($request) {
            $this->assertSame('/groups/2589355088', (string) parse_url($request->url(), PHP_URL_PATH));
            $this->assertSame('11', (string) $request->data()['topic_id']);

            return Http::response([
                'status' => 'ok',
                'max_message_id' => 321,
                'scanned_count' => 1,
                'items' => [[
                    'id' => 321,
                    'text' => '4dc6eb55ee68f197a332ca4802aaf14420f76d74',
                ]],
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--scan-only' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '4dc6eb55ee68f197a332ca4802aaf14420f76d74',
            'source_peer_id' => 2589355088,
            'source_message_id' => 321,
        ]);
    }

    public function test_type_two_scan_stores_only_wenjianji_codes_and_preserves_case(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $peerId = str_contains($path, '3779285711') ? 3779285711 : 2352070665;

            return Http::response([
                'status' => 'ok',
                'items' => [[
                    'id' => $peerId === 3779285711 ? 117824 : 21923,
                    'text' => implode("\n", [
                        '說明文字不應存入資料表',
                        'WenJianJiJibot_1v_imWSCOeauMVsszgd',
                        'wenjianjijibot_1v_imWSCOeauMVsszgd',
                        'WenJianJibot_1v_imWSCOeauMVsszgd',
                        'WenJianJiJibot_482v_24p_N0lb3TWh7jj2WEVD',
                        '@WenJianJiJibot',
                        'WenJianJiJibot這只是聊天文字',
                    ]),
                ]],
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--scan-only' => true,
            '--code-type' => 2,
            '--bot-username' => 'WenJianJiJibot',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('telegram_resource_codes', 2);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'WenJianJiJibot_1v_imWSCOeauMVsszgd',
            'code_type' => 2,
            'status' => TelegramResourceCode::STATUS_PENDING,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'WenJianJiJibot_482v_24p_N0lb3TWh7jj2WEVD',
            'code_type' => 2,
        ]);
        $this->assertDatabaseMissing('telegram_resource_codes', [
            'code' => 'WenJianJiJibot_1v_imwscoeaumvsszgd',
        ]);
    }

    public function test_type_three_scan_stores_qq_codes_from_multiple_prefixes(): void
    {
        Http::fake(Http::response([
            'status' => 'ok',
            'items' => [[
                'id' => 119900,
                'text' => implode("\n", [
                    'QQer16_bot:qqcode1ebfce2af1_3V',
                    'QQn8zw_bot:qqcode1099c74e81_8P_17V',
                    'QQyptu_bot:qqcode10884fe700_9V',
                    'QQyptu_bot:qqcode10882ee480_9V',
                    'QQfile_bot:10506_69799_143-42',
                    'QQnext_gen_bot:FutureCode_AZ09-7',
                    'QQyptu_bot qqcode10882ee480_9V',
                    'notQQyptu_bot:qqcode10882ee480_9V',
                ]),
            ]],
        ]));

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--scan-only' => true,
            '--code-type' => 3,
            '--bot-username' => 'QQyptu_bot',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('telegram_resource_codes', 6);
        foreach ([
            'QQer16_bot:qqcode1ebfce2af1_3V',
            'QQn8zw_bot:qqcode1099c74e81_8P_17V',
            'QQyptu_bot:qqcode10884fe700_9V',
            'QQyptu_bot:qqcode10882ee480_9V',
            'QQfile_bot:10506_69799_143-42',
            'QQnext_gen_bot:FutureCode_AZ09-7',
        ] as $code) {
            $this->assertDatabaseHas('telegram_resource_codes', [
                'code' => $code,
                'code_type' => 3,
                'status' => TelegramResourceCode::STATUS_PENDING,
            ]);
        }
    }

    public function test_type_four_scan_stores_only_jsfile_codes_and_preserves_suffix_case(): void
    {
        Http::fake(Http::response([
            'status' => 'ok',
            'items' => [[
                'id' => 119902,
                'text' => implode("\n", [
                    'JSfile_bot_87V0P0D_2TZN-NN8C',
                    'jsfile_bot_a1B2_c3-D4',
                    'JSfilebot_87V0P0D_2TZN-NN8C',
                    'notJSfile_bot_87V0P0D_2TZN-NN8C',
                ]),
            ]],
        ]));

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--scan-only' => true,
            '--code-type' => 4,
            '--bot-username' => 'JSfilesbot',
        ])->assertExitCode(0);

        $this->assertDatabaseCount('telegram_resource_codes', 2);
        foreach ([
            'JSfile_bot_87V0P0D_2TZN-NN8C',
            'JSfile_bot_a1B2_c3-D4',
        ] as $code) {
            $this->assertDatabaseHas('telegram_resource_codes', [
                'code' => $code,
                'code_type' => 4,
                'status' => TelegramResourceCode::STATUS_PENDING,
            ]);
        }
    }

    public function test_worker_scans_type_two_and_three_but_only_claims_type_three(): void
    {
        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response([
                    'status' => 'ok',
                    'items' => [[
                        'id' => 119901,
                        'text' => implode(' ', [
                            'WenJianJiJibot_1v_imWSCOeauMVsszgd',
                            'QQyptu_bot:qqcode10884fe700_9V',
                        ]),
                    ]],
                ]);
            }

            $this->assertSame('QQyptu_bot:qqcode10884fe700_9V', $request['code']);
            $this->assertSame('QQyptu_bot', $request['bot_username']);

            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 25,
                'expected_media_count' => 25,
                'declared_file_count' => 25,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
            '--code-type' => 3,
            '--scan-code-types' => '2,3',
            '--bot-username' => 'QQyptu_bot',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'WenJianJiJibot_1v_imWSCOeauMVsszgd',
            'code_type' => 2,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'QQyptu_bot:qqcode10884fe700_9V',
            'code_type' => 3,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
            'forwarded_message_count' => 25,
        ]);
    }

    public function test_specific_code_id_processes_only_the_requested_type_three_row(): void
    {
        $firstId = DB::table('telegram_resource_codes')->insertGetId([
            'code' => 'QQyptu_bot:qqcode10884fe700_9V',
            'code_type' => 3,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestedId = DB::table('telegram_resource_codes')->insertGetId([
            'code' => 'QQfile_bot:10506_69799_143-42',
            'code_type' => 3,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            $this->assertSame('QQfile_bot:10506_69799_143-42', $request['code']);
            $this->assertSame('QQ7bet_bot', $request['bot_username']);
            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 3,
                'expected_media_count' => 3,
                'declared_file_count' => 3,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
            '--code-type' => 3,
            '--code-id' => $requestedId,
            '--scan-code-types' => '3',
            '--bot-username' => 'QQ7bet_bot',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'id' => $firstId,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'id' => $requestedId,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
            'forwarded_message_count' => 3,
        ]);
    }

    public function test_multiple_profiles_process_all_configured_code_types(): void
    {
        config()->set('telegram.resource_codes.processing_profiles', '1:zyxfidi_bot,2:WenJianJiJibot,3:QQ2aij_bot,4:JSfilesbot');
        config()->set('telegram.resource_codes.scan_code_types', '1,2,3,4');

        DB::table('telegram_resource_codes')->insert([
            [
                'code' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'code_type' => 1,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'WenJianJiJibot_1v_EY7hgrHmiujLKVaV',
                'code_type' => 2,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'QQyptu_bot:qqcode10884fe700_9V',
                'code_type' => 3,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'JSfile_bot_87V0P0D_2TZN-NN8C',
                'code_type' => 4,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $sentBots = [];
        Http::fake(function ($request) use (&$sentBots) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            $sentBots[] = (string) $request['bot_username'];
            $forwardedCount = $request['bot_username'] === 'WenJianJiJibot' ? 2 : 1;
            return Http::response([
                'status' => 'ok',
                'forwarded_count' => $forwardedCount,
                'expected_media_count' => $forwardedCount,
                'declared_file_count' => $forwardedCount,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 4,
        ])->assertExitCode(0);

        $this->assertSame(['zyxfidi_bot', 'WenJianJiJibot', 'QQ2aij_bot', 'JSfilesbot'], $sentBots);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 2,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 3,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 4,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_multiple_profiles_rotate_code_types_instead_of_draining_one_type_first(): void
    {
        config()->set('telegram.resource_codes.processing_profiles', '1:zyxfidi_bot,2:WenJianJiJibot,3:QQ2aij_bot,4:JSfilesbot');
        config()->set('telegram.resource_codes.scan_code_types', '1,2,3,4');

        foreach ([
            ['aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1],
            ['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 1],
            ['WenJianJiJibot_1v_EY7hgrHmiujLKVaV', 2],
            ['QQfile_bot:10506_69799_143-42', 3],
            ['JSfile_bot_87V0P0D_2TZN-NN8C', 4],
        ] as [$code, $codeType]) {
            DB::table('telegram_resource_codes')->insert([
                'code' => $code,
                'code_type' => $codeType,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sentBots = [];
        Http::fake(function ($request) use (&$sentBots) {
            if (str_ends_with($request->url(), '/resource-codes/process')) {
                $sentBots[] = (string) $request['bot_username'];

                return Http::response([
                    'status' => 'ok',
                    'cleanup_complete' => true,
                    'forwarded_count' => 1,
                    'expected_media_count' => 1,
                    'declared_file_count' => 1,
                ]);
            }

            return Http::response(['status' => 'ok', 'items' => []]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 5,
        ])->assertExitCode(0);

        $this->assertSame([
            'zyxfidi_bot',
            'WenJianJiJibot',
            'QQ2aij_bot',
            'JSfilesbot',
            'zyxfidi_bot',
        ], $sentBots);
    }

    public function test_type_two_worker_does_not_claim_type_one_rows(): void
    {
        DB::table('telegram_resource_codes')->insert([
            [
                'code' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'code_type' => 1,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'WenJianJiJibot_1v_imWSCOeauMVsszgd',
                'code_type' => 2,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            $this->assertSame('WenJianJiJibot_1v_imWSCOeauMVsszgd', $request['code']);
            $this->assertSame('WenJianJiJibot', $request['bot_username']);

            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 1,
                'expected_media_count' => 1,
                'declared_file_count' => 1,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
            '--code-type' => 2,
            '--bot-username' => 'WenJianJiJibot',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 2,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
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
                    'expected_media_count' => 2,
                    'declared_file_count' => 2,
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
            'decoder_sent_count' => 2,
            'decoder_total_count' => 2,
        ]);
    }

    public function test_account_limit_switches_to_next_account_without_consuming_extra_attempt(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => 'QQn8zw_bot:qqcode1098a0b740_16V',
            'code_type' => 3,
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
                    'status' => 'error',
                    'reason' => 'account_limited',
                    'cleanup_complete' => true,
                ]);
            }

            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 16,
                'expected_media_count' => 16,
                'declared_file_count' => 16,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
            '--code-type' => 3,
            '--scan-code-types' => '3',
            '--bot-username' => 'QQyptu_bot',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'QQn8zw_bot:qqcode1098a0b740_16V',
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
            'processing_account' => 2,
            'forwarded_message_count' => 16,
        ]);

        $cooldownUntil = (int) Cache::store('telegram_resource_codes')->get(
            'telegram_resource_codes:decoder_cooldown:' . sha1('qqyptu_bot|http://127.0.0.1:8001'),
            0
        );
        $this->assertGreaterThanOrEqual(time() + 890, $cooldownUntil);
    }

    public function test_all_decoder_accounts_cooling_does_not_claim_pending_code(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => 'QQyptu_bot:qqcode10884fe700_9V',
            'code_type' => 3,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([8001, 8002, 8003] as $port) {
            $baseUri = "http://127.0.0.1:{$port}";
            Cache::store('telegram_resource_codes')->put(
                'telegram_resource_codes:decoder_cooldown:' . sha1('qqyptu_bot|' . $baseUri),
                time() + 900,
                now()->addMinutes(16)
            );
        }

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            return Http::response(['status' => 'unexpected_process_call'], 500);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
            '--code-type' => 3,
            '--scan-code-types' => '3',
            '--bot-username' => 'QQyptu_bot',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'QQyptu_bot:qqcode10884fe700_9V',
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'processing_started_at' => null,
        ]);
        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    }

    public function test_decoder_cooldown_for_one_bot_does_not_block_another_profile(): void
    {
        config()->set('telegram.resource_codes.processing_profiles', '1:zyxfidi_bot,2:WenJianJiJibot');
        config()->set('telegram.resource_codes.scan_code_types', '1,2,3');

        DB::table('telegram_resource_codes')->insert([
            [
                'code' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                'code_type' => 1,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => 'WenJianJiJibot_1v_EY7hgrHmiujLKVaV',
                'code_type' => 2,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        foreach ([8001, 8002, 8003] as $port) {
            $baseUri = "http://127.0.0.1:{$port}";
            Cache::store('telegram_resource_codes')->put(
                'telegram_resource_codes:decoder_cooldown:' . sha1('zyxfidi_bot|' . $baseUri),
                time() + 900,
                now()->addMinutes(16)
            );
        }

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            $this->assertSame('WenJianJiJibot', $request['bot_username']);
            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 1,
                'expected_media_count' => 1,
                'declared_file_count' => 1,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code_type' => 2,
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_untried_code_is_processed_before_an_older_retry(): void
    {
        DB::table('telegram_resource_codes')->insert([
            [
                'code' => '1111111111111111111111111111111111111111',
                'code_type' => 1,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 2,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'code' => '2222222222222222222222222222222222222222',
                'code_type' => 1,
                'status' => TelegramResourceCode::STATUS_PENDING,
                'attempts' => 0,
                'forwarded_message_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            $this->assertSame('2222222222222222222222222222222222222222', $request['code']);

            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 1,
                'expected_media_count' => 1,
                'declared_file_count' => 1,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '1111111111111111111111111111111111111111',
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 2,
        ]);
        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '2222222222222222222222222222222222222222',
            'status' => TelegramResourceCode::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_dormant_code_is_marked_skipped_and_never_left_pending(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => 'f2d32d20fd97cc0f7a40d0a7ab4e282d479c14d5',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            return Http::response([
                'status' => 'skip',
                'reason' => 'dormant',
                'cleanup_complete' => true,
                'forwarded_count' => 0,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => 'f2d32d20fd97cc0f7a40d0a7ab4e282d479c14d5',
            'status' => TelegramResourceCode::STATUS_SKIPPED,
            'skip_reason' => TelegramResourceCode::SKIP_REASON_DORMANT,
            'forwarded_message_count' => 0,
        ]);
        $this->assertNotNull(DB::table('telegram_resource_codes')->where('code', 'f2d32d20fd97cc0f7a40d0a7ab4e282d479c14d5')->value('skipped_at'));
    }

    public function test_partial_forward_count_is_not_marked_completed(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => '3333333333333333333333333333333333333333',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 0,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            return Http::response([
                'status' => 'ok',
                'forwarded_count' => 90,
                'expected_media_count' => 109,
                'declared_file_count' => 124,
                'cleanup_complete' => true,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '3333333333333333333333333333333333333333',
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 1,
            'forwarded_message_count' => 0,
        ]);
    }

    public function test_third_processing_failure_is_permanently_skipped(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => '4444444444444444444444444444444444444444',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 2,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            return Http::response([
                'status' => 'error',
                'reason' => 'media_timeout',
                'cleanup_complete' => true,
                'forwarded_count' => 0,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '4444444444444444444444444444444444444444',
            'status' => TelegramResourceCode::STATUS_SKIPPED,
            'skip_reason' => TelegramResourceCode::SKIP_REASON_RETRY_LIMIT,
            'attempts' => 3,
        ]);
        $row = DB::table('telegram_resource_codes')->where('code', '4444444444444444444444444444444444444444')->first();
        $this->assertNull($row->available_at);
        $this->assertNotNull($row->skipped_at);
    }

    public function test_second_processing_failure_remains_pending(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => '5555555555555555555555555555555555555555',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 1,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            return Http::response([
                'status' => 'error',
                'reason' => 'not_found',
                'cleanup_complete' => true,
                'forwarded_count' => 0,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '5555555555555555555555555555555555555555',
            'status' => TelegramResourceCode::STATUS_PENDING,
            'skip_reason' => null,
            'attempts' => 2,
        ]);
        $this->assertNotNull(DB::table('telegram_resource_codes')->where('code', '5555555555555555555555555555555555555555')->value('available_at'));
    }

    public function test_account_send_failure_does_not_consume_attempt_or_permanently_skip(): void
    {
        DB::table('telegram_resource_codes')->insert([
            'code' => '6666666666666666666666666666666666666666',
            'code_type' => 1,
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => 2,
            'forwarded_message_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake(function ($request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'GET' && str_starts_with($path, '/groups/')) {
                return Http::response(['status' => 'ok', 'items' => []]);
            }

            return Http::response([
                'status' => 'error',
                'reason' => 'processing_failed',
                'phase' => 'send_code',
                'cleanup_complete' => true,
                'forwarded_count' => 0,
            ]);
        });

        $this->artisan('telegram:process-resource-codes', [
            '--once' => true,
            '--process-limit' => 1,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('telegram_resource_codes', [
            'code' => '6666666666666666666666666666666666666666',
            'status' => TelegramResourceCode::STATUS_PENDING,
            'skip_reason' => null,
            'attempts' => 2,
        ]);
    }
}

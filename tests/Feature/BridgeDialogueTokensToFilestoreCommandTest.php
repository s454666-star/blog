<?php

namespace Tests\Feature;

use App\Services\DialogueFilestoreDispatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class BridgeDialogueTokensToFilestoreCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('dialogues');
        Schema::dropIfExists('telegram_filestore_sessions');

        Schema::create('dialogues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('text', 255);
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });

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
    }

    public function test_command_scans_dialogues_newest_first_and_skips_existing_source_tokens(): void
    {
        DB::table('dialogues')->insert([
            [
                'id' => 10,
                'chat_id' => 1,
                'message_id' => 10,
                'text' => 'mtfxqbot_5V_existing0001',
                'is_read' => 1,
                'created_at' => now(),
            ],
            [
                'id' => 11,
                'chat_id' => 1,
                'message_id' => 11,
                'text' => 'QQfile_bot:ignore_this_one',
                'is_read' => 1,
                'created_at' => now(),
            ],
            [
                'id' => 12,
                'chat_id' => 1,
                'message_id' => 12,
                'text' => 'note mtfxqbot_4V_newest0002 and QQfile_bot:14120_108172_755-39P_10V',
                'is_read' => 1,
                'created_at' => now(),
            ],
            [
                'id' => 13,
                'chat_id' => 1,
                'message_id' => 13,
                'text' => 'mtfxqbot_3V_second0003',
                'is_read' => 1,
                'created_at' => now(),
            ],
            [
                'id' => 14,
                'chat_id' => 1,
                'message_id' => 14,
                'text' => 'duplicate mtfxqbot_4V_newest0002',
                'is_read' => 1,
                'created_at' => now(),
            ],
        ]);

        DB::table('telegram_filestore_sessions')->insert([
            'chat_id' => 7702694790,
            'public_token' => 'filestoebot_5V_existing',
            'source_token' => 'mtfxqbot_5V_existing0001',
            'status' => 'closed',
            'total_files' => 5,
            'created_at' => now(),
            'closed_at' => now(),
        ]);

        $dispatches = [];

        $mock = Mockery::mock(DialogueFilestoreDispatchService::class);
        $mock->shouldReceive('dispatchToken')
            ->twice()
            ->andReturnUsing(function (string $token, array $options) use (&$dispatches): array {
                $dispatches[] = $token;
                $this->assertTrue($options['--filestore-delete-source-messages'] ?? false);

                $sessionId = DB::table('telegram_filestore_sessions')->insertGetId([
                    'chat_id' => 7702694790,
                    'public_token' => 'filestoebot_' . substr(md5($token), 0, 12),
                    'source_token' => $token,
                    'status' => 'closed',
                    'total_files' => 3,
                    'created_at' => now(),
                    'closed_at' => now(),
                ]);

                return [
                    'ok' => true,
                    'exit_code' => 0,
                    'session_id' => $sessionId,
                    'summary' => 'mock synced',
                ];
            });

        $this->app->instance(DialogueFilestoreDispatchService::class, $mock);

        $this->artisan('filestore:bridge-dialogues-tokens --prefix=mtfxqbot_ --limit=2')
            ->expectsOutputToContain('skipped_existing=1')
            ->expectsOutputToContain('skipped_dup_in_run=1')
            ->expectsOutputToContain('attempted=2')
            ->expectsOutputToContain('synced=2')
            ->assertExitCode(0);

        $this->assertSame([
            'mtfxqbot_4V_newest0002',
            'mtfxqbot_3V_second0003',
        ], $dispatches);

        $this->assertDatabaseHas('telegram_filestore_sessions', [
            'source_token' => 'mtfxqbot_4V_newest0002',
        ]);

        $this->assertDatabaseHas('telegram_filestore_sessions', [
            'source_token' => 'mtfxqbot_3V_second0003',
        ]);

        $this->assertDatabaseCount('telegram_filestore_sessions', 3);
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackfillMzksrBotChatsCommandTest extends TestCase
{
    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('mzksr_bot_chats');

        Schema::create('mzksr_bot_chats', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->string('chat_type', 32)->nullable();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('title')->nullable();
            $table->bigInteger('last_message_id')->nullable();
            $table->unsignedInteger('interaction_count')->default(0);
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
        });

        $this->logPath = storage_path('logs/test-mzksr-backfill.log');
        if (!is_dir(dirname($this->logPath))) {
            mkdir(dirname($this->logPath), 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->logPath) && is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    public function test_command_backfills_unique_chat_ids_from_mzksr_logs(): void
    {
        file_put_contents($this->logPath, implode(PHP_EOL, [
            '[2026-02-13 19:31:13] local.INFO: Telegram sendMessage success {"body":{"ok":true,"result":{"message_id":13,"from":{"id":8388175026,"is_bot":true,"first_name":"夢之國機器人","username":"mzksr_bot"},"chat":{"id":8491679630,"first_name":"小","last_name":"白","username":"s4546663","type":"private"},"date":1770982272,"text":"https://t.me/+wTLMiobPi6RiMjU1"}}}',
            '[2026-02-24 17:33:30] local.INFO: Telegram webhook incoming {"has_message":true,"chat_id":7223536027,"text":"@mzksr_bot"}',
            '[2026-02-24 17:33:31] local.INFO: Telegram webhook incoming {"has_message":true,"chat_id":8491679630,"text":"夢之國"}',
            '[2026-02-24 17:33:32] local.INFO: Telegram sendMessage success {"body":{"ok":true,"result":{"message_id":99,"from":{"id":7921552608,"is_bot":true,"first_name":"Code Dedup","username":"code_dedup_bot"},"chat":{"id":9000000000,"type":"private"}}}}',
            '',
        ]));

        $this->artisan('telegram:mzksr-backfill-chats', [
            '--path' => $this->logPath,
        ])
            ->expectsOutputToContain('files_scanned=1')
            ->expectsOutputToContain('matched_lines=3')
            ->expectsOutputToContain('unique_chat_ids=2')
            ->assertExitCode(0);

        $this->assertDatabaseCount('mzksr_bot_chats', 2);
        $this->assertDatabaseHas('mzksr_bot_chats', [
            'chat_id' => 8491679630,
            'chat_type' => 'private',
            'username' => 's4546663',
            'first_name' => '小',
            'last_name' => '白',
            'interaction_count' => 2,
        ]);
        $this->assertDatabaseHas('mzksr_bot_chats', [
            'chat_id' => 7223536027,
            'interaction_count' => 1,
        ]);
        $this->assertDatabaseMissing('mzksr_bot_chats', [
            'chat_id' => 9000000000,
        ]);
    }
}

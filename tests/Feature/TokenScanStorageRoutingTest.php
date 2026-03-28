<?php

namespace Tests\Feature;

use App\Console\Commands\ScanGroupMediaCommand;
use App\Console\Commands\ScanGroupTokensCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Console\OutputStyle;
use Tests\TestCase;

class TokenScanStorageRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('token_scan_items');
        Schema::dropIfExists('token_scan_headers');
        Schema::dropIfExists('dialogues');

        Schema::create('token_scan_headers', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('peer_id');
            $table->string('chat_title')->nullable();
            $table->unsignedBigInteger('last_start_message_id')->nullable();
            $table->unsignedBigInteger('max_message_id')->nullable();
            $table->unsignedBigInteger('last_batch_count')->nullable();
            $table->timestamps();
        });

        Schema::create('token_scan_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('header_id')->nullable();
            $table->string('token', 255);
            $table->timestamps();
        });

        Schema::create('dialogues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('text', 255);
            $table->boolean('is_read')->default(false);
            $table->dateTime('created_at')->nullable();
        });
    }

    public function test_scan_group_tokens_store_messenger_qq_yz_and_vipfiles_family_only_in_dialogues(): void
    {
        $command = $this->app->make(ScanGroupTokensCommand::class);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));
        $method = new ReflectionMethod($command, 'extractAndInsertTokensFromItems');
        $method->setAccessible(true);

        $inserted = $method->invoke($command, 99, [[
            'message' => 'Messengercode_abc123 showfilesbot_123P_abcdef QQfile_bot:14120_108172_755-39P_10V yzfile_bot:abc123 mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
        ]]);

        $this->assertSame(5, $inserted);
        $this->assertDatabaseCount('dialogues', 5);
        $this->assertDatabaseCount('token_scan_items', 1);

        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 1,
            'text' => 'Messengercode_abc123',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 2,
            'text' => 'showfilesbot_123P_abcdef',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 3,
            'text' => 'QQfile_bot:14120_108172_755-39P_10V',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 4,
            'text' => 'yzfile_bot:abc123',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 5,
            'text' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'is_read' => 1,
        ]);

        $this->assertDatabaseHas('token_scan_items', [
            'header_id' => 99,
            'token' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'Messengercode_abc123',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'showfilesbot_123P_abcdef',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'QQfile_bot:14120_108172_755-39P_10V',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'yzfile_bot:abc123',
        ]);
    }

    public function test_scan_group_media_stores_special_and_vipfiles_prefixes_in_dialogues_without_queueing_them(): void
    {
        $command = $this->app->make(ScanGroupMediaCommand::class);
        $method = new ReflectionMethod($command, 'queueTokenIfNeeded');
        $method->setAccessible(true);

        $messengerResult = $method->invoke($command, 321, 'Media Chat', 'Messengercode_abc123', 1001);
        $qqResult = $method->invoke($command, 321, 'Media Chat', 'QQfile_bot:14120_108172_755-39P_10V', 1002);
        $yzResult = $method->invoke($command, 321, 'Media Chat', 'yzfile_bot:abc123', 1003);
        $vipfilesResult = $method->invoke($command, 321, 'Media Chat', 'showfilesbot_123P_abcdef', 1004);
        $normalResult = $method->invoke($command, 321, 'Media Chat', 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2', 1005);

        $this->assertSame('dialogues_only', $messengerResult);
        $this->assertSame('dialogues_only', $qqResult);
        $this->assertSame('dialogues_only', $yzResult);
        $this->assertSame('dialogues_only', $vipfilesResult);
        $this->assertSame('queued', $normalResult);

        $this->assertDatabaseCount('dialogues', 5);
        $this->assertDatabaseCount('token_scan_items', 1);
        $this->assertDatabaseCount('token_scan_headers', 1);

        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 1,
            'text' => 'Messengercode_abc123',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 2,
            'text' => 'QQfile_bot:14120_108172_755-39P_10V',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 3,
            'text' => 'yzfile_bot:abc123',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 4,
            'text' => 'showfilesbot_123P_abcdef',
            'is_read' => 1,
        ]);
        $this->assertDatabaseHas('dialogues', [
            'chat_id' => 7702694790,
            'message_id' => 5,
            'text' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
            'is_read' => 1,
        ]);

        $this->assertDatabaseHas('token_scan_headers', [
            'peer_id' => 321,
            'chat_title' => 'Media Chat',
        ]);
        $this->assertDatabaseHas('token_scan_items', [
            'token' => 'mtfxqbot_13P_1V_51t7y7v4u5i6I6v5p7A2',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'Messengercode_abc123',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'QQfile_bot:14120_108172_755-39P_10V',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'yzfile_bot:abc123',
        ]);
        $this->assertDatabaseMissing('token_scan_items', [
            'token' => 'showfilesbot_123P_abcdef',
        ]);
    }
}

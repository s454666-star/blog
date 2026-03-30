<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TelegramWebhookControllerTest extends TestCase
{
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
    }

    public function test_mystar_secure_webhook_records_and_updates_chat_summary(): void
    {
        $firstResponse = $this->postJson('/api/telegram/webhook/mystar-secure', [
            'message' => [
                'message_id' => 101,
                'date' => 1770982272,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'first_name' => '小',
                    'last_name' => '白',
                    'username' => 's4546663',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'type' => 'private',
                    'first_name' => '小',
                    'last_name' => '白',
                    'username' => 's4546663',
                ],
                'text' => 'hello',
            ],
        ]);

        $secondResponse = $this->postJson('/api/telegram/webhook/mystar-secure', [
            'message' => [
                'message_id' => 102,
                'date' => 1770982372,
                'from' => [
                    'id' => 8491679630,
                    'is_bot' => false,
                    'first_name' => '小',
                    'last_name' => '白',
                    'username' => 's4546663_new',
                ],
                'chat' => [
                    'id' => 8491679630,
                    'type' => 'private',
                    'first_name' => '小',
                    'last_name' => '白',
                    'username' => 's4546663_new',
                ],
                'text' => 'hello again',
            ],
        ]);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        $this->assertDatabaseCount('mzksr_bot_chats', 1);
        $this->assertDatabaseHas('mzksr_bot_chats', [
            'chat_id' => 8491679630,
            'chat_type' => 'private',
            'username' => 's4546663_new',
            'first_name' => '小',
            'last_name' => '白',
            'last_message_id' => 102,
            'interaction_count' => 2,
        ]);
    }

    public function test_mystar_secure_webhook_records_non_text_messages_too(): void
    {
        $response = $this->postJson('/api/telegram/webhook/mystar-secure', [
            'message' => [
                'message_id' => 303,
                'date' => 1771925609,
                'from' => [
                    'id' => 7223536027,
                    'is_bot' => false,
                    'first_name' => 'n',
                    'last_name' => 'l',
                ],
                'chat' => [
                    'id' => 7223536027,
                    'type' => 'private',
                    'first_name' => 'n',
                    'last_name' => 'l',
                ],
                'photo' => [
                    ['file_id' => 'abc'],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJson(['status' => 'no text']);

        $this->assertDatabaseHas('mzksr_bot_chats', [
            'chat_id' => 7223536027,
            'chat_type' => 'private',
            'first_name' => 'n',
            'last_name' => 'l',
            'last_message_id' => 303,
            'interaction_count' => 1,
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mzksr_bot_chats')) {
            return;
        }

        Schema::create('mzksr_bot_chats', function (Blueprint $table): void {
            $table->id();
            $table->bigInteger('chat_id');
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

            $table->unique('chat_id', 'mzksr_bot_chats_chat_id_unique');
            $table->index('last_seen_at', 'mzksr_bot_chats_last_seen_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mzksr_bot_chats');
    }
};

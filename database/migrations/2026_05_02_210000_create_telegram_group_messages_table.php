<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_group_messages', function (Blueprint $table): void {
            $table->id();
            $table->longText('group_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('message_code', 128)->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_group_messages');
    }
};

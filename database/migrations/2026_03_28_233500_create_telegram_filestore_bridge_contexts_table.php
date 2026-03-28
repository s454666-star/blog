<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_filestore_bridge_contexts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('context_type', 32);
            $table->char('context_hash', 64);
            $table->string('context_value', 255)->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();

            $table->unique(
                ['context_type', 'context_hash'],
                'telegram_filestore_bridge_contexts_type_hash_unique'
            );
            $table->index('session_id', 'telegram_filestore_bridge_contexts_session_idx');
            $table->index('expires_at', 'telegram_filestore_bridge_contexts_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_filestore_bridge_contexts');
    }
};

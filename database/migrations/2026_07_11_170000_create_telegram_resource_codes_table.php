<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_resource_codes', function (Blueprint $table): void {
            $table->id();
            $table->char('code', 40);
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

            $table->unique('code', 'uq_telegram_resource_codes_code');
            $table->index(['status', 'available_at', 'id'], 'idx_telegram_resource_codes_queue');
            $table->index(['source_peer_id', 'source_message_id'], 'idx_telegram_resource_codes_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_resource_codes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('group_media_scan_states')) {
            return;
        }

        Schema::create('group_media_scan_states', function (Blueprint $table) {
            $table->id();
            $table->string('base_uri', 255);
            $table->unsignedBigInteger('peer_id');
            $table->string('chat_title')->nullable();
            $table->unsignedBigInteger('max_message_id')->default(1);
            $table->unsignedBigInteger('last_group_message_id')->default(0);
            $table->unsignedBigInteger('last_downloaded_message_id')->default(0);
            $table->unsignedInteger('last_batch_count')->default(0);
            $table->dateTime('last_message_datetime')->nullable();
            $table->text('last_saved_path')->nullable();
            $table->string('last_saved_name')->nullable();
            $table->timestamps();

            $table->unique(['base_uri', 'peer_id'], 'group_media_scan_states_base_uri_peer_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_media_scan_states');
    }
};

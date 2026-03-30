<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_filestore_restore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_session_id')->nullable();
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->string('source_token')->nullable();
            $table->string('source_public_token')->nullable();
            $table->string('target_bot_username');
            $table->unsignedBigInteger('target_chat_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('processed_files')->default(0);
            $table->unsignedInteger('success_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->unsignedBigInteger('last_source_file_id')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_session_id', 'target_bot_username'], 'tg_filestore_restore_sessions_source_target_idx');
            $table->index('source_token', 'tg_filestore_restore_sessions_source_token_idx');
            $table->index('source_public_token', 'tg_filestore_restore_sessions_public_token_idx');
        });

        Schema::create('telegram_filestore_restore_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restore_session_id');
            $table->unsignedBigInteger('source_session_id')->nullable();
            $table->unsignedBigInteger('source_file_row_id');
            $table->unsignedBigInteger('source_chat_id')->nullable();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->string('source_file_id')->nullable();
            $table->string('source_file_unique_id')->nullable();
            $table->string('source_token')->nullable();
            $table->string('source_public_token')->nullable();
            $table->unsignedBigInteger('forwarded_message_id')->nullable();
            $table->unsignedBigInteger('target_chat_id')->nullable();
            $table->unsignedBigInteger('target_message_id')->nullable();
            $table->string('target_file_id')->nullable();
            $table->string('target_file_unique_id')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type', 32)->default('document');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('raw_payload')->nullable();
            $table->dateTime('forwarded_at')->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['restore_session_id', 'source_file_row_id'], 'tg_filestore_restore_files_restore_source_uq');
            $table->index(['source_session_id', 'source_file_row_id'], 'tg_filestore_restore_files_source_idx');
            $table->index('source_token', 'tg_filestore_restore_files_source_token_idx');
            $table->index('source_public_token', 'tg_filestore_restore_files_public_token_idx');
            $table->index('status', 'tg_filestore_restore_files_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_filestore_restore_files');
        Schema::dropIfExists('telegram_filestore_restore_sessions');
    }
};

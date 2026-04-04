<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_rerun_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->default('running');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('db_seen_count')->default(0);
            $table->unsignedInteger('rerun_seen_count')->default(0);
            $table->unsignedInteger('eagle_seen_count')->default(0);
            $table->unsignedInteger('hashed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('missing_file_count')->default(0);
            $table->unsignedInteger('diff_group_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->json('summary_json')->nullable();
            $table->timestamps();
            $table->index(['status', 'started_at'], 'idx_video_rerun_sync_runs_status_started');
        });

        Schema::create('video_rerun_sync_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 32);
            $table->string('source_key', 191);
            $table->string('source_item_id', 191)->nullable();
            $table->string('resource_key', 255)->nullable();
            $table->string('display_name', 500)->nullable();
            $table->string('relative_path', 1000)->nullable();
            $table->string('absolute_path', 1000)->nullable();
            $table->string('file_extension', 32)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->char('content_sha1', 40)->nullable();
            $table->string('fingerprint_status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('last_seen_run_id')->nullable();
            $table->dateTime('discovered_at')->nullable();
            $table->dateTime('fingerprinted_at')->nullable();
            $table->boolean('is_present')->default(true);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_key'], 'uq_video_rerun_sync_entries_source_key');
            $table->index(['source_type', 'is_present'], 'idx_video_rerun_sync_entries_source_present');
            $table->index('content_sha1', 'idx_video_rerun_sync_entries_sha1');
            $table->index('fingerprint_status', 'idx_video_rerun_sync_entries_status');

            $table->foreign('last_seen_run_id', 'fk_video_rerun_sync_entries_last_seen_run')
                ->references('id')
                ->on('video_rerun_sync_runs')
                ->nullOnDelete();
        });

        Schema::create('video_rerun_sync_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action_type', 32);
            $table->char('content_sha1', 40)->nullable();
            $table->string('target_source', 32);
            $table->string('target_key', 191)->nullable();
            $table->string('status', 32)->default('success');
            $table->text('message')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['action_type', 'created_at'], 'idx_video_rerun_sync_action_logs_action_created');
            $table->index(['target_source', 'status'], 'idx_video_rerun_sync_action_logs_source_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_rerun_sync_action_logs');
        Schema::dropIfExists('video_rerun_sync_entries');
        Schema::dropIfExists('video_rerun_sync_runs');
    }
};

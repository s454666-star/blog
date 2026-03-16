<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('external_video_duplicate_logs')) {
            return;
        }

        Schema::create('external_video_duplicate_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('external_video_duplicate_match_id')->nullable();
            $table->integer('video_master_id')->nullable();
            $table->unsignedBigInteger('matched_video_feature_id')->nullable();
            $table->string('scan_root_path', 500)->nullable();
            $table->string('source_directory_path', 500)->nullable();
            $table->string('source_file_path', 500);
            $table->char('source_path_sha1', 40)->nullable();
            $table->string('duplicate_file_path', 500)->nullable();
            $table->char('duplicate_path_sha1', 40)->nullable();
            $table->string('file_name', 255);
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('duration_seconds', 10, 3)->default(0);
            $table->dateTime('file_created_at')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->unsignedTinyInteger('screenshot_count')->default(0);
            $table->string('feature_version', 32)->default('v1');
            $table->string('capture_rule', 64)->default('10s_x4');
            $table->unsignedTinyInteger('threshold_percent')->default(90);
            $table->unsignedTinyInteger('requested_min_match')->default(2);
            $table->unsignedTinyInteger('required_matches')->nullable();
            $table->unsignedInteger('window_seconds')->default(3);
            $table->unsignedTinyInteger('size_percent')->default(15);
            $table->unsignedInteger('max_candidates')->default(250);
            $table->unsignedInteger('candidate_count')->default(0);
            $table->decimal('similarity_percent', 5, 2)->nullable();
            $table->unsignedTinyInteger('matched_frames')->default(0);
            $table->unsignedTinyInteger('compared_frames')->default(0);
            $table->decimal('duration_delta_seconds', 10, 3)->nullable();
            $table->bigInteger('file_size_delta_bytes')->nullable();
            $table->boolean('is_duplicate_detected')->default(false);
            $table->string('operation_status', 32)->default('no_match');
            $table->text('operation_message')->nullable();
            $table->longText('source_feature_json')->nullable();
            $table->longText('matched_feature_json')->nullable();
            $table->longText('frame_comparisons_json')->nullable();
            $table->timestamps();

            $table->index('external_video_duplicate_match_id', 'idx_ext_video_dup_logs_match');
            $table->index('video_master_id', 'idx_ext_video_dup_logs_video_master');
            $table->index('matched_video_feature_id', 'idx_ext_video_dup_logs_feature');
            $table->index('source_path_sha1', 'idx_ext_video_dup_logs_source_sha1');
            $table->index('duplicate_path_sha1', 'idx_ext_video_dup_logs_duplicate_sha1');
            $table->index('file_name', 'idx_ext_video_dup_logs_file_name');
            $table->index('similarity_percent', 'idx_ext_video_dup_logs_similarity');
            $table->index('is_duplicate_detected', 'idx_ext_video_dup_logs_is_duplicate');
            $table->index('operation_status', 'idx_ext_video_dup_logs_status');

            $table->foreign('external_video_duplicate_match_id', 'fk_ext_video_dup_logs_match')
                ->references('id')
                ->on('external_video_duplicate_matches')
                ->nullOnDelete();

            $table->foreign('video_master_id', 'fk_ext_video_dup_logs_video_master')
                ->references('id')
                ->on('video_master')
                ->nullOnDelete();

            $table->foreign('matched_video_feature_id', 'fk_ext_video_dup_logs_feature')
                ->references('id')
                ->on('video_features')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_video_duplicate_logs');
    }
};

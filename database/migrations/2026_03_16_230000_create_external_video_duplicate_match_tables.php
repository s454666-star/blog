<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('external_video_duplicate_matches')) {
            Schema::create('external_video_duplicate_matches', function (Blueprint $table) {
                $table->id();
                $table->integer('video_master_id')->nullable();
                $table->foreignId('matched_video_feature_id')->nullable();
                $table->string('scan_root_path', 500)->nullable();
                $table->string('duplicate_directory_path', 500);
                $table->string('source_directory_path', 500)->nullable();
                $table->string('source_file_path', 500);
                $table->char('source_path_sha1', 40)->nullable();
                $table->string('duplicate_file_path', 500);
                $table->char('duplicate_path_sha1', 40);
                $table->string('file_name', 255);
                $table->unsignedBigInteger('file_size_bytes')->nullable();
                $table->decimal('duration_seconds', 10, 3)->default(0);
                $table->dateTime('file_created_at')->nullable();
                $table->dateTime('file_modified_at')->nullable();
                $table->unsignedTinyInteger('screenshot_count')->default(0);
                $table->string('feature_version', 32)->default('v1');
                $table->string('capture_rule', 64)->default('10s_x4');
                $table->unsignedTinyInteger('threshold_percent')->default(90);
                $table->unsignedTinyInteger('min_match_required')->default(2);
                $table->unsignedInteger('window_seconds')->default(3);
                $table->unsignedTinyInteger('size_percent')->default(15);
                $table->decimal('similarity_percent', 5, 2)->default(0);
                $table->unsignedTinyInteger('matched_frames')->default(0);
                $table->unsignedTinyInteger('compared_frames')->default(0);
                $table->decimal('duration_delta_seconds', 10, 3)->nullable();
                $table->bigInteger('file_size_delta_bytes')->nullable();
                $table->timestamps();

                $table->unique('duplicate_path_sha1', 'uq_external_video_dup_matches_duplicate_path_sha1');
                $table->index('source_path_sha1', 'idx_external_video_dup_matches_source_sha1');
                $table->index('file_name', 'idx_external_video_dup_matches_file_name');
                $table->index('video_master_id', 'idx_external_video_dup_matches_video_master');
                $table->index('matched_video_feature_id', 'idx_external_video_dup_matches_feature');
                $table->index('similarity_percent', 'idx_external_video_dup_matches_similarity');

                $table->foreign('video_master_id', 'fk_external_video_dup_matches_video_master')
                    ->references('id')
                    ->on('video_master')
                    ->nullOnDelete();

                $table->foreign('matched_video_feature_id', 'fk_external_video_dup_matches_feature')
                    ->references('id')
                    ->on('video_features')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasTable('external_video_duplicate_frames')) {
            Schema::create('external_video_duplicate_frames', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('external_video_duplicate_match_id');
                $table->unsignedBigInteger('matched_video_feature_frame_id')->nullable();
                $table->unsignedTinyInteger('capture_order');
                $table->decimal('capture_second', 10, 3)->default(0);
                $table->string('screenshot_path', 500);
                $table->char('dhash_hex', 16);
                $table->char('dhash_prefix', 2);
                $table->char('frame_sha1', 40)->nullable();
                $table->unsignedInteger('image_width')->nullable();
                $table->unsignedInteger('image_height')->nullable();
                $table->unsignedTinyInteger('similarity_percent')->nullable();
                $table->boolean('is_threshold_match')->default(false);
                $table->timestamps();

                $table->unique(
                    ['external_video_duplicate_match_id', 'capture_order'],
                    'uq_external_video_dup_frames_match_order'
                );
                $table->index('matched_video_feature_frame_id', 'idx_external_video_dup_frames_feature_frame');
                $table->index('dhash_prefix', 'idx_external_video_dup_frames_dhash_prefix');
                $table->index('similarity_percent', 'idx_external_video_dup_frames_similarity');

                $table->foreign('external_video_duplicate_match_id', 'fk_ext_video_dup_frames_match')
                    ->references('id')
                    ->on('external_video_duplicate_matches')
                    ->cascadeOnDelete();
                $table->foreign('matched_video_feature_frame_id', 'fk_external_video_dup_frames_feature_frame')
                    ->references('id')
                    ->on('video_feature_frames')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_video_duplicate_frames');
        Schema::dropIfExists('external_video_duplicate_matches');
    }
};

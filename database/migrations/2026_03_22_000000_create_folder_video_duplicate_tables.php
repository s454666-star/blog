<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('folder_video_duplicate_batches')) {
            Schema::create('folder_video_duplicate_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('scan_root_path', 500);
                $table->string('duplicate_directory_path', 500);
                $table->boolean('is_recursive')->default(true);
                $table->unsignedTinyInteger('threshold_percent')->default(80);
                $table->unsignedTinyInteger('min_match_required')->default(2);
                $table->unsignedInteger('window_seconds')->default(3);
                $table->unsignedInteger('max_candidates')->default(250);
                $table->unsignedInteger('limit_count')->nullable();
                $table->boolean('is_dry_run')->default(false);
                $table->boolean('cleanup_requested')->default(true);
                $table->string('status', 32)->default('running');
                $table->unsignedInteger('total_files')->default(0);
                $table->unsignedInteger('processed_files')->default(0);
                $table->unsignedInteger('kept_files')->default(0);
                $table->unsignedInteger('moved_files')->default(0);
                $table->unsignedInteger('failed_files')->default(0);
                $table->dateTime('started_at')->nullable();
                $table->dateTime('finished_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->index('status', 'idx_folder_video_dup_batches_status');
                $table->index('started_at', 'idx_folder_video_dup_batches_started_at');
            });
        }

        if (!Schema::hasTable('folder_video_duplicate_features')) {
            Schema::create('folder_video_duplicate_features', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('folder_video_duplicate_batch_id');
                $table->string('absolute_path', 500);
                $table->char('path_sha1', 40);
                $table->string('directory_path', 500)->nullable();
                $table->string('file_name', 255);
                $table->unsignedBigInteger('file_size_bytes')->nullable();
                $table->decimal('duration_seconds', 10, 3)->default(0);
                $table->dateTime('file_created_at')->nullable();
                $table->dateTime('file_modified_at')->nullable();
                $table->unsignedTinyInteger('screenshot_count')->default(0);
                $table->string('feature_version', 32)->default('v1');
                $table->string('capture_rule', 64)->default('10s_x4');
                $table->boolean('is_canonical')->default(true);
                $table->string('moved_to_duplicate_path', 500)->nullable();
                $table->string('extraction_status', 32)->default('ready');
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(
                    ['folder_video_duplicate_batch_id', 'path_sha1'],
                    'uq_folder_video_dup_features_batch_path_sha1'
                );
                $table->index(
                    ['folder_video_duplicate_batch_id', 'is_canonical'],
                    'idx_folder_video_dup_features_batch_canonical'
                );
                $table->index(
                    ['folder_video_duplicate_batch_id', 'duration_seconds'],
                    'idx_folder_video_dup_features_batch_duration'
                );
                $table->index('file_name', 'idx_folder_video_dup_features_file_name');
                $table->index('path_sha1', 'idx_folder_video_dup_features_path_sha1');

                $table->foreign('folder_video_duplicate_batch_id', 'fk_folder_video_dup_features_batch')
                    ->references('id')
                    ->on('folder_video_duplicate_batches')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('folder_video_duplicate_frames')) {
            Schema::create('folder_video_duplicate_frames', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('folder_video_duplicate_feature_id');
                $table->unsignedTinyInteger('capture_order');
                $table->decimal('capture_second', 10, 3)->default(0);
                $table->char('dhash_hex', 16);
                $table->char('dhash_prefix', 2);
                $table->char('frame_sha1', 40)->nullable();
                $table->unsignedInteger('image_width')->nullable();
                $table->unsignedInteger('image_height')->nullable();
                $table->timestamps();

                $table->unique(
                    ['folder_video_duplicate_feature_id', 'capture_order'],
                    'uq_folder_video_dup_frames_feature_order'
                );
                $table->index('dhash_prefix', 'idx_folder_video_dup_frames_dhash_prefix');

                $table->foreign('folder_video_duplicate_feature_id', 'fk_folder_video_dup_frames_feature')
                    ->references('id')
                    ->on('folder_video_duplicate_features')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('folder_video_duplicate_matches')) {
            Schema::create('folder_video_duplicate_matches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('folder_video_duplicate_batch_id');
                $table->unsignedBigInteger('kept_feature_id');
                $table->unsignedBigInteger('duplicate_feature_id');
                $table->string('kept_file_path', 500);
                $table->string('duplicate_file_path', 500);
                $table->char('duplicate_path_sha1', 40);
                $table->string('moved_to_path', 500)->nullable();
                $table->decimal('similarity_percent', 5, 2)->default(0);
                $table->unsignedTinyInteger('matched_frames')->default(0);
                $table->unsignedTinyInteger('compared_frames')->default(0);
                $table->unsignedTinyInteger('required_matches')->default(0);
                $table->decimal('duration_delta_seconds', 10, 3)->nullable();
                $table->bigInteger('file_size_delta_bytes')->nullable();
                $table->longText('frame_comparisons_json')->nullable();
                $table->string('operation_status', 32)->default('match_moved');
                $table->text('operation_message')->nullable();
                $table->timestamps();

                $table->index('folder_video_duplicate_batch_id', 'idx_folder_video_dup_matches_batch');
                $table->index('kept_feature_id', 'idx_folder_video_dup_matches_kept_feature');
                $table->index('duplicate_feature_id', 'idx_folder_video_dup_matches_dup_feature');
                $table->index('duplicate_path_sha1', 'idx_folder_video_dup_matches_dup_path_sha1');
                $table->index('similarity_percent', 'idx_folder_video_dup_matches_similarity');
                $table->index('operation_status', 'idx_folder_video_dup_matches_status');

                $table->foreign('folder_video_duplicate_batch_id', 'fk_folder_video_dup_matches_batch')
                    ->references('id')
                    ->on('folder_video_duplicate_batches')
                    ->cascadeOnDelete();
                $table->foreign('kept_feature_id', 'fk_folder_video_dup_matches_kept_feature')
                    ->references('id')
                    ->on('folder_video_duplicate_features')
                    ->cascadeOnDelete();
                $table->foreign('duplicate_feature_id', 'fk_folder_video_dup_matches_dup_feature')
                    ->references('id')
                    ->on('folder_video_duplicate_features')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_video_duplicate_matches');
        Schema::dropIfExists('folder_video_duplicate_frames');
        Schema::dropIfExists('folder_video_duplicate_features');
        Schema::dropIfExists('folder_video_duplicate_batches');
    }
};

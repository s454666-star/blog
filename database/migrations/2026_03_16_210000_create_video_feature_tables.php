<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_features', function (Blueprint $table) {
            $table->id();
            $table->integer('video_master_id')->unique();
            $table->integer('master_face_screenshot_id')->nullable();
            $table->string('video_name', 255);
            $table->string('video_path', 500);
            $table->string('directory_path', 500)->nullable();
            $table->string('file_name', 255);
            $table->char('path_sha1', 40)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->decimal('duration_seconds', 10, 3)->default(0);
            $table->dateTime('file_created_at')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->unsignedTinyInteger('screenshot_count')->default(0);
            $table->string('feature_version', 32)->default('v1');
            $table->string('capture_rule', 64)->default('10s_x4');
            $table->dateTime('extracted_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('master_face_screenshot_id', 'idx_video_features_master_face');
            $table->index('file_name', 'idx_video_features_file_name');
            $table->index('path_sha1', 'idx_video_features_path_sha1');
            $table->index(['duration_seconds', 'file_size_bytes'], 'idx_video_features_duration_size');

            $table->foreign('video_master_id', 'fk_video_features_video_master')
                ->references('id')
                ->on('video_master')
                ->cascadeOnDelete();

            $table->foreign('master_face_screenshot_id', 'fk_video_features_master_face')
                ->references('id')
                ->on('video_face_screenshots')
                ->nullOnDelete();
        });

        Schema::create('video_feature_frames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_feature_id')
                ->constrained('video_features')
                ->cascadeOnDelete();
            $table->integer('video_screenshot_id')->nullable();
            $table->unsignedTinyInteger('capture_order');
            $table->decimal('capture_second', 10, 3)->default(0);
            $table->string('screenshot_path', 500);
            $table->char('dhash_hex', 16);
            $table->char('dhash_prefix', 2);
            $table->char('frame_sha1', 40)->nullable();
            $table->unsignedInteger('image_width')->nullable();
            $table->unsignedInteger('image_height')->nullable();
            $table->timestamps();

            $table->unique(['video_feature_id', 'capture_order'], 'uq_video_feature_frames_order');
            $table->index('video_screenshot_id', 'idx_video_feature_frames_screenshot');
            $table->index('dhash_prefix', 'idx_video_feature_frames_dhash_prefix');
            $table->index('dhash_hex', 'idx_video_feature_frames_dhash_hex');

            $table->foreign('video_screenshot_id', 'fk_video_feature_frames_screenshot')
                ->references('id')
                ->on('video_screenshots')
                ->nullOnDelete();
        });

        Schema::create('video_feature_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('left_video_feature_id')
                ->constrained('video_features')
                ->cascadeOnDelete();
            $table->foreignId('right_video_feature_id')
                ->constrained('video_features')
                ->cascadeOnDelete();
            $table->decimal('similarity_percent', 5, 2)->default(0);
            $table->unsignedTinyInteger('matched_frames')->default(0);
            $table->unsignedTinyInteger('compared_frames')->default(0);
            $table->decimal('duration_delta_seconds', 10, 3)->nullable();
            $table->bigInteger('file_size_delta_bytes')->nullable();
            $table->dateTime('compared_at')->nullable();
            $table->json('notes_json')->nullable();
            $table->timestamps();

            $table->unique(['left_video_feature_id', 'right_video_feature_id'], 'uq_video_feature_matches_pair');
            $table->index('similarity_percent', 'idx_video_feature_matches_similarity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_feature_matches');
        Schema::dropIfExists('video_feature_frames');
        Schema::dropIfExists('video_features');
    }
};

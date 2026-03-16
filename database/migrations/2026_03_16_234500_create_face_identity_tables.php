<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_identity_people', function (Blueprint $table) {
            $table->id();
            $table->string('feature_model', 64)->default('facenet_pytorch_vggface2_mtcnn_v1');
            $table->string('cover_sample_path', 500)->nullable();
            $table->unsignedInteger('video_count')->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->longText('centroid_embedding_json')->nullable();
            $table->timestamps();

            $table->index(['feature_model', 'last_seen_at'], 'idx_face_identity_people_model_seen');
        });

        Schema::create('face_identity_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')
                ->nullable()
                ->constrained('face_identity_people')
                ->nullOnDelete();
            $table->string('feature_model', 64)->default('facenet_pytorch_vggface2_mtcnn_v1');
            $table->string('source_root_label', 100)->nullable();
            $table->string('source_root_path', 500)->nullable();
            $table->string('relative_directory', 500)->nullable();
            $table->string('relative_path', 700);
            $table->text('absolute_path');
            $table->string('file_name', 255);
            $table->char('path_sha1', 40)->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->decimal('duration_seconds', 10, 3)->default(0);
            $table->unsignedInteger('frame_interval_seconds')->default(240);
            $table->unsignedTinyInteger('accepted_sample_count')->default(0);
            $table->string('preview_sample_path', 500)->nullable();
            $table->decimal('match_confidence', 6, 4)->nullable();
            $table->string('assignment_source', 16)->default('auto');
            $table->boolean('group_locked')->default(false);
            $table->string('scan_status', 32)->default('pending');
            $table->dateTime('last_scanned_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['person_id', 'last_scanned_at'], 'idx_face_identity_videos_person_scanned');
            $table->index(['scan_status', 'last_scanned_at'], 'idx_face_identity_videos_status_scanned');
            $table->index(['feature_model', 'assignment_source'], 'idx_face_identity_videos_model_source');
        });

        Schema::create('face_identity_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')
                ->constrained('face_identity_videos')
                ->cascadeOnDelete();
            $table->foreignId('person_id')
                ->nullable()
                ->constrained('face_identity_people')
                ->nullOnDelete();
            $table->string('feature_model', 64)->default('facenet_pytorch_vggface2_mtcnn_v1');
            $table->unsignedTinyInteger('capture_order');
            $table->decimal('capture_second', 10, 3)->default(0);
            $table->string('image_path', 500);
            $table->longText('embedding_json');
            $table->char('embedding_sha1', 40)->nullable();
            $table->decimal('detector_score', 6, 4)->nullable();
            $table->decimal('quality_score', 8, 3)->nullable();
            $table->decimal('blur_score', 10, 3)->nullable();
            $table->decimal('frontal_score', 8, 3)->nullable();
            $table->json('bbox_json')->nullable();
            $table->json('landmarks_json')->nullable();
            $table->timestamps();

            $table->unique(['video_id', 'capture_order'], 'uq_face_identity_samples_order');
            $table->index(['person_id', 'feature_model'], 'idx_face_identity_samples_person_model');
            $table->index('embedding_sha1', 'idx_face_identity_samples_embedding_sha1');
        });

        Schema::create('face_identity_group_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')
                ->constrained('face_identity_videos')
                ->cascadeOnDelete();
            $table->foreignId('from_person_id')
                ->nullable()
                ->constrained('face_identity_people')
                ->nullOnDelete();
            $table->foreignId('to_person_id')
                ->nullable()
                ->constrained('face_identity_people')
                ->nullOnDelete();
            $table->string('action', 32);
            $table->string('note', 255)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at'], 'idx_face_identity_group_changes_action_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_identity_group_changes');
        Schema::dropIfExists('face_identity_samples');
        Schema::dropIfExists('face_identity_videos');
        Schema::dropIfExists('face_identity_people');
    }
};

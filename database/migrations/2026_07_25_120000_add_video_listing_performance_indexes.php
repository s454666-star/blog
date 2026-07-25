<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_master', function (Blueprint $table): void {
            $table->index(
                ['video_type', 'duration', 'id'],
                'idx_video_master_type_duration_id'
            );
            $table->index(
                ['video_type', 'id'],
                'idx_video_master_type_id'
            );
        });

        Schema::table('video_face_screenshots', function (Blueprint $table): void {
            $table->index(
                ['video_screenshot_id', 'is_master', 'id'],
                'idx_video_faces_screenshot_master_id'
            );
        });
    }

    public function down(): void
    {
        Schema::table('video_face_screenshots', function (Blueprint $table): void {
            $table->dropIndex('idx_video_faces_screenshot_master_id');
        });

        Schema::table('video_master', function (Blueprint $table): void {
            $table->dropIndex('idx_video_master_type_duration_id');
            $table->dropIndex('idx_video_master_type_id');
        });
    }
};

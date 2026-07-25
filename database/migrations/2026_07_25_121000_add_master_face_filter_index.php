<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_face_screenshots', function (Blueprint $table): void {
            $table->index(
                ['is_master', 'video_screenshot_id', 'id'],
                'idx_video_faces_master_screenshot_id'
            );
        });
    }

    public function down(): void
    {
        Schema::table('video_face_screenshots', function (Blueprint $table): void {
            $table->dropIndex('idx_video_faces_master_screenshot_id');
        });
    }
};

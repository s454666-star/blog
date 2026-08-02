<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tg_video_reviews', function (Blueprint $table): void {
            $table->id();
            $table->char('path_hash', 40)->unique();
            $table->text('video_path');
            $table->text('image_path');
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedBigInteger('file_modified_at');
            $table->decimal('duration_seconds', 12, 3);
            $table->unsignedTinyInteger('screenshot_count')->default(20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tg_video_reviews');
    }
};

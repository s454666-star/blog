<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('folder_video_dislikes')) {
            Schema::create('folder_video_dislikes', function (Blueprint $table): void {
                $table->id();
                // sha1 of the API video id; one row per disliked video, removed on undo.
                $table->char('video_key', 40)->unique();
                $table->string('video_id', 1024);
                $table->string('source', 16)->default('main');
                $table->string('filename', 1024);
                $table->string('client', 16)->nullable();
                $table->dateTime('disliked_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_video_dislikes');
    }
};

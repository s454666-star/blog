<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('folder_video_plays')) {
            Schema::create('folder_video_plays', function (Blueprint $table): void {
                $table->id();
                // sha1 of the API video id; ids are base64 paths and can exceed index limits.
                $table->char('video_key', 40)->index();
                $table->string('video_id', 1024);
                $table->string('source', 16)->default('main');
                $table->string('filename', 1024);
                $table->string('client', 16)->nullable();
                $table->dateTime('played_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_video_plays');
    }
};

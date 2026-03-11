<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('btdig_result_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('btdig_result_id');
            $table->string('search_keyword', 100);
            $table->unsignedBigInteger('keyword_number');
            $table->string('search_url', 500);
            $table->string('article_url', 500);
            $table->text('article_title')->nullable();
            $table->string('viewimage_url', 500);
            $table->string('image_url', 2048);
            $table->string('image_mime_type', 100)->nullable();
            $table->string('image_extension', 20)->nullable();
            $table->unsignedBigInteger('image_size_bytes')->nullable();
            $table->char('image_sha1', 40);
            $table->longText('image_base64');
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->foreign('btdig_result_id')
                ->references('id')
                ->on('btdig_results')
                ->cascadeOnDelete();

            $table->unique(['btdig_result_id', 'viewimage_url'], 'uq_btdig_result_images_viewimage');
            $table->index('search_keyword');
            $table->index('keyword_number');
            $table->index('image_sha1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('btdig_result_images');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawler_profile_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->default('synthetic');
            $table->string('external_user_id', 120)->nullable();
            $table->string('nickname', 120);
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('area', 40)->nullable();
            $table->text('profile_url')->nullable();
            $table->json('matched_filter_json')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_user_id'], 'uq_crawler_profile_candidates_source_user');
            $table->index(['source', 'area', 'age'], 'idx_crawler_profile_candidates_filter');
            $table->index('captured_at', 'idx_crawler_profile_candidates_captured_at');
        });

        Schema::create('crawler_profile_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawler_profile_candidate_id')
                ->constrained('crawler_profile_candidates')
                ->cascadeOnDelete();
            $table->text('image_url');
            $table->char('image_url_hash', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['crawler_profile_candidate_id', 'sort_order'], 'idx_crawler_profile_images_candidate_sort');
            $table->unique(['crawler_profile_candidate_id', 'image_url_hash'], 'uq_crawler_profile_images_candidate_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawler_profile_images');
        Schema::dropIfExists('crawler_profile_candidates');
    }
};

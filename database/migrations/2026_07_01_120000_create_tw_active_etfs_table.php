<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_active_etfs', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_code', 12)->unique();
            $table->string('stock_name');
            $table->string('management_type')->nullable();
            $table->string('etf_category', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'stock_code'], 'idx_tw_active_etfs_active_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_active_etfs');
    }
};

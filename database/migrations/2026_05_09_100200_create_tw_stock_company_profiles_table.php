<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_company_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->string('industry_code', 8)->nullable();
            $table->string('valuation_group', 32);
            $table->decimal('valuation_group_pe', 8, 4);
            $table->date('source_date')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'stock_code'], 'uniq_tw_stock_company_profiles_exchange_code');
            $table->index(['exchange', 'industry'], 'idx_tw_stock_company_profiles_exchange_industry');
            $table->index('valuation_group', 'idx_tw_stock_company_profiles_valuation_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_company_profiles');
    }
};

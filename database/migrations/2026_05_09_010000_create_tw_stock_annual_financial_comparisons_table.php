<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('context_year');
            $table->unsignedSmallInteger('comparison_start_year');
            $table->unsignedSmallInteger('comparison_end_year');
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->decimal('revenue_yoy_sum', 20, 4)->nullable();
            $table->decimal('eps_yoy_sum', 20, 4)->nullable();
            $table->decimal('recent_net_margin_average', 12, 4)->nullable();
            $table->decimal('last_two_year_net_margin_average', 12, 4)->nullable();
            $table->boolean('revenue_filter_pass')->default(false);
            $table->boolean('eps_filter_pass')->default(false);
            $table->boolean('net_margin_filter_pass')->default(false);
            $table->decimal('current_revenue_billion', 20, 4)->nullable();
            $table->unsignedTinyInteger('current_revenue_months')->default(0);
            $table->decimal('current_eps', 12, 4)->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->unsignedInteger('volume_lots')->nullable();
            $table->json('comparisons');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['context_year', 'exchange', 'stock_code'], 'uq_tw_stock_annual_comparisons_stock');
            $table->index(['context_year', 'revenue_yoy_sum'], 'idx_tw_stock_annual_comparisons_revenue');
            $table->index(['context_year', 'eps_yoy_sum'], 'idx_tw_stock_annual_comparisons_eps');
            $table->index(['context_year', 'revenue_filter_pass', 'eps_filter_pass', 'net_margin_filter_pass'], 'idx_tw_stock_annual_comparisons_filters');
            $table->index('stock_code', 'idx_tw_stock_annual_comparisons_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_annual_financial_comparisons');
    }
};

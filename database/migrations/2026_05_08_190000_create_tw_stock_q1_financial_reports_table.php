<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter')->default(1);
            $table->string('financial_period', 8);
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->decimal('q1_revenue_billion', 14, 4)->nullable();
            $table->decimal('q1_revenue_yoy_percent', 10, 4)->nullable();
            $table->decimal('q1_revenue_score', 8, 4)->nullable();
            $table->decimal('q1_eps', 10, 4)->nullable();
            $table->decimal('q1_eps_yoy_percent', 10, 4)->nullable();
            $table->decimal('q1_gross_margin_percent', 10, 4)->nullable();
            $table->decimal('q1_operating_margin_percent', 10, 4)->nullable();
            $table->decimal('q1_net_margin_percent', 10, 4)->nullable();
            $table->decimal('q1_net_income_billion', 14, 4)->nullable();
            $table->decimal('roe_percent', 10, 4)->nullable();
            $table->decimal('roa_percent', 10, 4)->nullable();
            $table->decimal('operating_profit_mix_percent', 10, 4)->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->unsignedInteger('volume_lots')->nullable();
            $table->decimal('price_change_1d_percent', 10, 4)->nullable();
            $table->decimal('price_change_5d_percent', 10, 4)->nullable();
            $table->decimal('price_change_20d_percent', 10, 4)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_year', 'quarter', 'exchange', 'stock_code'], 'uq_tw_stock_q1_financial_reports_stock');
            $table->index(['fiscal_year', 'quarter', 'rank'], 'idx_tw_stock_q1_financial_reports_rank');
            $table->index(['fiscal_year', 'quarter', 'q1_revenue_score'], 'idx_tw_stock_q1_financial_reports_score');
            $table->index('stock_code', 'idx_tw_stock_q1_financial_reports_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_q1_financial_reports');
    }
};

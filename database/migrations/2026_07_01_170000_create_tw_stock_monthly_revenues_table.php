<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_monthly_revenues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('revenue_year');
            $table->unsignedTinyInteger('revenue_month');
            $table->date('announced_date')->nullable();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->bigInteger('monthly_revenue_thousands')->nullable();
            $table->bigInteger('previous_month_revenue_thousands')->nullable();
            $table->bigInteger('last_year_month_revenue_thousands')->nullable();
            $table->decimal('month_over_month_percent', 12, 4)->nullable();
            $table->decimal('year_over_year_percent', 12, 4)->nullable();
            $table->decimal('mom_yoy_sum_percent', 12, 4)->nullable();
            $table->bigInteger('cumulative_revenue_thousands')->nullable();
            $table->bigInteger('last_year_cumulative_revenue_thousands')->nullable();
            $table->decimal('cumulative_yoy_percent', 12, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->decimal('one_day_change_percent', 10, 4)->nullable();
            $table->decimal('five_day_change_percent', 10, 4)->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'stock_code', 'revenue_year', 'revenue_month'], 'uq_tw_stock_monthly_revenues_stock_period');
            $table->index(['revenue_year', 'revenue_month', 'mom_yoy_sum_percent'], 'idx_tw_stock_monthly_revenues_period_sum');
            $table->index(['revenue_year', 'revenue_month', 'month_over_month_percent'], 'idx_tw_stock_monthly_revenues_period_mom');
            $table->index(['revenue_year', 'revenue_month', 'year_over_year_percent'], 'idx_tw_stock_monthly_revenues_period_yoy');
            $table->index(['stock_code', 'revenue_year', 'revenue_month'], 'idx_tw_stock_monthly_revenues_code_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_monthly_revenues');
    }
};

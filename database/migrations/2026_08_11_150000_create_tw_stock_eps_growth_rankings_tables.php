<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_eps_growth_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->date('price_date')->nullable();
            $table->unsignedSmallInteger('base_year');
            $table->unsignedSmallInteger('forecast_year_1');
            $table->unsignedSmallInteger('forecast_year_2');
            $table->unsignedSmallInteger('forecast_year_3');
            $table->unsignedInteger('article_count')->default(0);
            $table->unsignedInteger('forecast_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('top_count')->default(0);
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['snapshot_date', 'completed_at'], 'idx_tw_stock_eps_runs_snapshot_completed');
        });

        Schema::create('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')
                ->constrained('tw_stock_eps_growth_runs')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('previous_rank')->nullable();
            $table->smallInteger('rank_change')->nullable();
            $table->string('stock_code', 12);
            $table->string('stock_name', 80);
            $table->decimal('eps_2025', 16, 4);
            $table->decimal('eps_2026', 16, 4);
            $table->decimal('eps_2027', 16, 4);
            $table->decimal('eps_2028', 16, 4);
            $table->decimal('growth_2025_2026', 14, 4);
            $table->decimal('growth_2026_2027', 14, 4);
            $table->decimal('growth_2027_2028', 14, 4);
            $table->decimal('growth_sum', 14, 4);
            $table->bigInteger('revenue_2026_thousands')->nullable();
            $table->bigInteger('revenue_2027_thousands')->nullable();
            $table->bigInteger('revenue_2028_thousands')->nullable();
            $table->date('price_date')->nullable();
            $table->decimal('close_price', 14, 4)->nullable();
            $table->unsignedSmallInteger('analyst_count')->nullable();
            $table->date('forecast_date')->nullable();
            $table->unsignedBigInteger('news_id')->nullable();
            $table->boolean('low_base')->default(false);
            $table->timestamps();

            $table->unique(['run_id', 'stock_code'], 'uq_tw_stock_eps_rankings_run_stock');
            $table->index(['run_id', 'rank'], 'idx_tw_stock_eps_rankings_run_rank');
            $table->index(['stock_code', 'run_id'], 'idx_tw_stock_eps_rankings_stock_run');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_eps_growth_rankings');
        Schema::dropIfExists('tw_stock_eps_growth_runs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->decimal('current_q1_eps_yoy_percent', 10, 4)->nullable()->after('current_eps');
            $table->decimal('current_q1_revenue_yoy_percent', 10, 4)->nullable()->after('current_q1_eps_yoy_percent');
            $table->decimal('end_year_revenue_yoy_percent', 10, 4)->nullable()->after('current_q1_revenue_yoy_percent');
            $table->index(['context_year', 'current_q1_eps_yoy_percent'], 'idx_tw_stock_annual_comparisons_q1_eps_yoy');
            $table->index(['context_year', 'current_q1_revenue_yoy_percent'], 'idx_tw_stock_annual_comparisons_q1_revenue_yoy');
            $table->index(['context_year', 'end_year_revenue_yoy_percent'], 'idx_tw_stock_annual_comparisons_end_revenue_yoy');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->dropIndex('idx_tw_stock_annual_comparisons_q1_eps_yoy');
            $table->dropIndex('idx_tw_stock_annual_comparisons_q1_revenue_yoy');
            $table->dropIndex('idx_tw_stock_annual_comparisons_end_revenue_yoy');
            $table->dropColumn([
                'current_q1_eps_yoy_percent',
                'current_q1_revenue_yoy_percent',
                'end_year_revenue_yoy_percent',
            ]);
        });
    }
};

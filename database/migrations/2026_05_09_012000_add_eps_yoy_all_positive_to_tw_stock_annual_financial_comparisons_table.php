<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->boolean('eps_yoy_all_positive')->default(false)->after('eps_filter_pass');
            $table->index(['context_year', 'eps_yoy_all_positive'], 'idx_tw_stock_annual_comparisons_eps_positive');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->dropIndex('idx_tw_stock_annual_comparisons_eps_positive');
            $table->dropColumn('eps_yoy_all_positive');
        });
    }
};

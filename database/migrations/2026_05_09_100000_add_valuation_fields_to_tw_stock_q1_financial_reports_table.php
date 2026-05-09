<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->string('valuation_group', 32)->nullable()->after('industry');
            $table->decimal('valuation_group_pe', 8, 4)->nullable()->after('valuation_group');
            $table->index(['fiscal_year', 'quarter', 'valuation_group'], 'idx_tw_stock_q1_reports_valuation_group');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->dropIndex('idx_tw_stock_q1_reports_valuation_group');
            $table->dropColumn(['valuation_group', 'valuation_group_pe']);
        });
    }
};

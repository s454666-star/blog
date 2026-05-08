<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->json('recent_monthly_revenues')->nullable()->after('operating_profit_mix_percent');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->dropColumn('recent_monthly_revenues');
        });
    }
};

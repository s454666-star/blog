<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_upcoming_dividends', function (Blueprint $table): void {
            $table->decimal('stock_dividend', 12, 6)->default(0)->after('cash_dividend');
            $table->decimal('price_20_days_ago', 12, 4)->nullable()->after('latest_price_date');
            $table->date('price_20_days_ago_date')->nullable()->after('price_20_days_ago');
            $table->decimal('price_change_20_days_percent', 8, 4)->nullable()->after('price_20_days_ago_date');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_upcoming_dividends', function (Blueprint $table): void {
            $table->dropColumn([
                'stock_dividend',
                'price_20_days_ago',
                'price_20_days_ago_date',
                'price_change_20_days_percent',
            ]);
        });
    }
};

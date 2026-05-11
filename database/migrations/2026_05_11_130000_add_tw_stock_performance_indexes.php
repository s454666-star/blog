<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tw_stock_daily_prices')) {
            Schema::table('tw_stock_daily_prices', function (Blueprint $table): void {
                $table->index(['trade_date', 'volume_lots'], 'idx_tw_stock_daily_prices_date_volume');
                $table->index(['trade_date', 'close_price'], 'idx_tw_stock_daily_prices_date_close');
                $table->index(['trade_date', 'price_change_amount'], 'idx_tw_stock_daily_prices_date_amount');
                $table->index(['stock_code', 'exchange', 'trade_date'], 'idx_tw_stock_daily_prices_code_exchange_date');
            });
        }

        if (Schema::hasTable('tw_stock_q1_financial_reports')) {
            Schema::table('tw_stock_q1_financial_reports', function (Blueprint $table): void {
                $table->index(['fiscal_year', 'quarter', 'volume_lots'], 'idx_tw_stock_q1_reports_year_quarter_volume');
                $table->index(['fiscal_year', 'quarter', 'latest_close_price'], 'idx_tw_stock_q1_reports_year_quarter_price');
            });
        }

        if (Schema::hasTable('tw_stock_upcoming_dividends')) {
            Schema::table('tw_stock_upcoming_dividends', function (Blueprint $table): void {
                $table->index(['ex_dividend_date', 'dividend_yield_percent'], 'idx_tw_stock_dividends_date_yield');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tw_stock_upcoming_dividends')) {
            Schema::table('tw_stock_upcoming_dividends', function (Blueprint $table): void {
                $table->dropIndex('idx_tw_stock_dividends_date_yield');
            });
        }

        if (Schema::hasTable('tw_stock_q1_financial_reports')) {
            Schema::table('tw_stock_q1_financial_reports', function (Blueprint $table): void {
                $table->dropIndex('idx_tw_stock_q1_reports_year_quarter_volume');
                $table->dropIndex('idx_tw_stock_q1_reports_year_quarter_price');
            });
        }

        if (Schema::hasTable('tw_stock_daily_prices')) {
            Schema::table('tw_stock_daily_prices', function (Blueprint $table): void {
                $table->dropIndex('idx_tw_stock_daily_prices_date_volume');
                $table->dropIndex('idx_tw_stock_daily_prices_date_close');
                $table->dropIndex('idx_tw_stock_daily_prices_date_amount');
                $table->dropIndex('idx_tw_stock_daily_prices_code_exchange_date');
            });
        }
    }
};

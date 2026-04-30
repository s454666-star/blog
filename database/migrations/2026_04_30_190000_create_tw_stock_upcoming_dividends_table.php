<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_upcoming_dividends', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('security_type', 24)->default('stock');
            $table->date('ex_dividend_date');
            $table->string('ex_dividend_type', 16);
            $table->decimal('cash_dividend', 12, 6);
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->decimal('dividend_yield_percent', 8, 4)->nullable();
            $table->unsignedInteger('days_until_ex_dividend');
            $table->date('last_ex_dividend_date')->nullable();
            $table->decimal('last_ex_dividend_cash_dividend', 12, 6)->nullable();
            $table->decimal('last_ex_dividend_before_price', 12, 4)->nullable();
            $table->date('last_fill_date')->nullable();
            $table->unsignedInteger('last_fill_days')->nullable();
            $table->string('last_fill_status', 24)->default('no_history');
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'stock_code', 'ex_dividend_date'], 'uq_tw_stock_upcoming_dividends_event');
            $table->index('ex_dividend_date', 'idx_tw_stock_upcoming_dividends_ex_date');
            $table->index('stock_code', 'idx_tw_stock_upcoming_dividends_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_upcoming_dividends');
    }
};

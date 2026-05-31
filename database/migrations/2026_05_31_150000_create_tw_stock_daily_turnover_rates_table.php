<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_daily_turnover_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->date('trade_date');
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedBigInteger('trading_shares')->default(0);
            $table->unsignedBigInteger('issued_shares')->nullable();
            $table->decimal('turnover_rate_percent', 10, 4)->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'stock_code', 'trade_date'], 'uq_tw_stock_turnover_stock_date');
            $table->index(['trade_date', 'turnover_rate_percent'], 'idx_tw_stock_turnover_date_rate');
            $table->index(['stock_code', 'exchange', 'trade_date'], 'idx_tw_stock_turnover_code_ex_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_daily_turnover_rates');
    }
};

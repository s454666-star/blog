<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->date('trade_date');
            $table->decimal('open_price', 12, 4)->nullable();
            $table->decimal('high_price', 12, 4)->nullable();
            $table->decimal('low_price', 12, 4)->nullable();
            $table->decimal('close_price', 12, 4);
            $table->decimal('previous_close_price', 12, 4)->nullable();
            $table->decimal('price_change_amount', 12, 4)->nullable();
            $table->decimal('price_change_percent', 10, 4)->nullable();
            $table->unsignedBigInteger('volume_lots')->default(0);
            $table->unsignedBigInteger('volume_shares')->default(0);
            $table->unsignedBigInteger('trade_value')->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'stock_code', 'trade_date'], 'uq_tw_stock_daily_prices_stock_date');
            $table->index(['trade_date', 'price_change_percent'], 'idx_tw_stock_daily_prices_rank');
            $table->index(['stock_code', 'trade_date'], 'idx_tw_stock_daily_prices_code_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_daily_prices');
    }
};

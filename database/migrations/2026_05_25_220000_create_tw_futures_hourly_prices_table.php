<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_futures_hourly_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 16)->default('TAIFEX');
            $table->string('symbol', 32);
            $table->string('symbol_name')->default('台指期近月連續');
            $table->string('interval', 8)->default('60');
            $table->dateTime('started_at');
            $table->unsignedBigInteger('started_at_unix');
            $table->date('trade_date')->nullable();
            $table->string('session_type', 16)->nullable();
            $table->decimal('open_price', 12, 4);
            $table->decimal('high_price', 12, 4);
            $table->decimal('low_price', 12, 4);
            $table->decimal('close_price', 12, 4);
            $table->unsignedBigInteger('volume_contracts')->default(0);
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'symbol', 'interval', 'started_at'], 'uq_tw_futures_hourly_prices_symbol_time');
            $table->index(['symbol', 'started_at'], 'idx_tw_futures_hourly_prices_symbol_time');
            $table->index(['trade_date', 'session_type'], 'idx_tw_futures_hourly_prices_trade_session');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_futures_hourly_prices');
    }
};

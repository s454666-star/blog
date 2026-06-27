<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_futures_daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 16)->default('TAIFEX');
            $table->string('symbol', 32);
            $table->string('symbol_name')->default('台指期近月連續');
            $table->string('contract_code', 16)->default('TX');
            $table->string('contract_month', 16);
            $table->date('trade_date');
            $table->string('session_type', 16)->default('day');
            $table->decimal('open_price', 12, 4);
            $table->decimal('high_price', 12, 4);
            $table->decimal('low_price', 12, 4);
            $table->decimal('close_price', 12, 4);
            $table->decimal('settlement_price', 12, 4)->nullable();
            $table->unsignedBigInteger('volume_contracts')->default(0);
            $table->unsignedBigInteger('open_interest')->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->json('verified_sources')->nullable();
            $table->string('validation_status', 24)->default('verified');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'symbol', 'session_type', 'trade_date'], 'uq_tw_futures_daily_prices_symbol_date');
            $table->index(['symbol', 'trade_date'], 'idx_tw_futures_daily_prices_symbol_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_futures_daily_prices');
    }
};

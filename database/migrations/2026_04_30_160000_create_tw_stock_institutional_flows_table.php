<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_institutional_flows', function (Blueprint $table): void {
            $table->id();
            $table->date('trade_date')->unique();

            $table->unsignedBigInteger('foreign_stock_buy_amount')->nullable();
            $table->unsignedBigInteger('foreign_stock_sell_amount')->nullable();
            $table->bigInteger('foreign_stock_net_amount')->nullable();

            $table->unsignedBigInteger('investment_trust_stock_buy_amount')->nullable();
            $table->unsignedBigInteger('investment_trust_stock_sell_amount')->nullable();
            $table->bigInteger('investment_trust_stock_net_amount')->nullable();

            $table->integer('foreign_txf_trade_net_contracts')->nullable();
            $table->integer('investment_trust_txf_trade_net_contracts')->nullable();

            $table->unsignedInteger('foreign_txf_open_interest_long_contracts')->nullable();
            $table->unsignedInteger('foreign_txf_open_interest_short_contracts')->nullable();
            $table->integer('foreign_txf_open_interest_net_contracts')->nullable();

            $table->unsignedInteger('investment_trust_txf_open_interest_long_contracts')->nullable();
            $table->unsignedInteger('investment_trust_txf_open_interest_short_contracts')->nullable();
            $table->integer('investment_trust_txf_open_interest_net_contracts')->nullable();

            $table->string('twse_source_title')->nullable();
            $table->json('twse_payload')->nullable();
            $table->json('taifex_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index('trade_date', 'idx_tw_stock_institutional_flows_trade_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_institutional_flows');
    }
};

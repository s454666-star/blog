<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persistent calculated results make the scheduled command append-only and idempotent.
        Schema::create('portfolio_dividend_incomes', function (Blueprint $table): void {
            $table->id();
            $table->string('broker', 16);
            $table->string('stock_code', 32);
            $table->string('stock_name')->nullable();
            $table->date('ex_dividend_date');
            $table->decimal('cash_dividend_per_share', 18, 6);
            $table->decimal('eligible_quantity', 18, 4);
            $table->decimal('dividend_income', 18, 4);
            $table->string('source', 120);
            $table->string('calculation_method', 80);
            $table->json('source_payload')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->unique(
                ['broker', 'stock_code', 'ex_dividend_date'],
                'uq_portfolio_dividend_broker_stock_date',
            );
            $table->index(['broker', 'ex_dividend_date'], 'idx_portfolio_dividend_broker_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_dividend_incomes');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yuanta_portfolio_daily_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('queried_at')->nullable();
            $table->string('source_status', 32)->nullable();
            $table->string('source_message')->nullable();
            $table->unsignedInteger('source_age_seconds')->nullable();
            $table->unsignedInteger('stock_count')->default(0);
            $table->decimal('share_count', 18, 4)->default(0);
            $table->decimal('market_value', 18, 4)->nullable();
            $table->decimal('cost_basis', 18, 4)->nullable();
            $table->decimal('today_pnl', 18, 4)->nullable();
            $table->decimal('unrealized_pnl', 18, 4)->nullable();
            $table->decimal('bank_balance', 18, 4)->nullable();
            $table->decimal('margin_used_amount', 18, 4)->nullable();
            $table->decimal('margin_available_amount', 18, 4)->nullable();
            $table->json('summary');
            $table->json('rows');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique('snapshot_date', 'uq_yuanta_portfolio_daily_snapshots_date');
            $table->index('captured_at', 'idx_yuanta_portfolio_daily_snapshots_captured');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yuanta_portfolio_daily_snapshots');
    }
};

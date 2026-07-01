<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_active_etf_operation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('tw_active_etf_operation_reports')
                ->cascadeOnDelete();
            $table->string('etf_code', 12);
            $table->string('etf_name');
            $table->date('operation_date');
            $table->string('stock_code', 16);
            $table->string('stock_name');
            $table->string('action', 16);
            $table->string('action_label', 16);
            $table->bigInteger('change_shares')->nullable();
            $table->decimal('change_lots', 14, 3)->nullable();
            $table->string('source_status', 32)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['report_id', 'stock_code', 'action'], 'uq_tw_active_etf_items_report_stock_action');
            $table->index(['operation_date', 'action'], 'idx_tw_active_etf_items_date_action');
            $table->index(['etf_code', 'operation_date', 'action'], 'idx_tw_active_etf_items_etf_date_action');
            $table->index('stock_code', 'idx_tw_active_etf_items_stock_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_active_etf_operation_items');
    }
};

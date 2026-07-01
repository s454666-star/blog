<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_active_etf_operation_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('etf_code', 12);
            $table->string('etf_name');
            $table->date('operation_date');
            $table->string('source_kind', 48)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->unsignedInteger('source_row_count')->default(0);
            $table->unsignedInteger('changed_row_count')->default(0);
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['etf_code', 'operation_date'], 'uq_tw_active_etf_reports_etf_date');
            $table->index('operation_date', 'idx_tw_active_etf_reports_date');
            $table->index(['etf_code', 'operation_date'], 'idx_tw_active_etf_reports_etf_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_active_etf_operation_reports');
    }
};

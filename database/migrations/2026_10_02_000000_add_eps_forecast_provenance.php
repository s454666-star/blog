<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tw_stock_eps_growth_runs', function (Blueprint $table): void {
            $table->json('forecast_audit')->nullable();
        });
        Schema::table('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->json('forecast_metadata')->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('tw_stock_eps_growth_rankings', fn (Blueprint $table) => $table->dropColumn('forecast_metadata'));
        Schema::table('tw_stock_eps_growth_runs', fn (Blueprint $table) => $table->dropColumn('forecast_audit'));
    }
};

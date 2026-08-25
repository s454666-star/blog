<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->boolean('is_neutral_estimate')->default(false)->after('weighted_score');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->dropColumn('is_neutral_estimate');
        });
    }
};

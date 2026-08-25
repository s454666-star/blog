<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->decimal('weighted_score', 7, 4)->nullable()->after('growth_sum');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->dropColumn('weighted_score');
        });
    }
};

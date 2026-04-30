<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_stock_institutional_flows', function (Blueprint $table): void {
            $table->decimal('taiex_open_index', 10, 2)->nullable()->after('investment_trust_txf_open_interest_net_contracts');
            $table->decimal('taiex_high_index', 10, 2)->nullable()->after('taiex_open_index');
            $table->decimal('taiex_low_index', 10, 2)->nullable()->after('taiex_high_index');
            $table->decimal('taiex_close_index', 10, 2)->nullable()->after('taiex_low_index');
            $table->string('taiex_source_title')->nullable()->after('taiex_close_index');
            $table->json('taiex_payload')->nullable()->after('taiex_source_title');
        });
    }

    public function down(): void
    {
        Schema::table('tw_stock_institutional_flows', function (Blueprint $table): void {
            $table->dropColumn([
                'taiex_open_index',
                'taiex_high_index',
                'taiex_low_index',
                'taiex_close_index',
                'taiex_source_title',
                'taiex_payload',
            ]);
        });
    }
};

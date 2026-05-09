<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tw_stock_valuation_groups')) {
            DB::table('tw_stock_valuation_groups')->updateOrInsert(
                ['group_name' => '其他業'],
                [
                    'average_pe' => 16.0,
                    'market_reference_pe' => 25.0,
                    'source_date' => '2026-05-08',
                    'source_note' => 'TWSE/TPEx 2026-05-08 official PE data: 其他業 <=80x median 15.41, average 21.61; rounded benchmark PE 16.',
                    'sort_order' => 160,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        if (Schema::hasTable('tw_stock_company_profiles')) {
            DB::table('tw_stock_company_profiles')
                ->whereIn('industry', ['其他業', '存託憑證'])
                ->update([
                    'valuation_group' => '其他業',
                    'valuation_group_pe' => 16.0,
                    'updated_at' => now(),
                ]);
        }

        if (Schema::hasTable('tw_stock_q1_financial_reports')) {
            DB::table('tw_stock_q1_financial_reports')
                ->whereIn('industry', ['其他業', '存託憑證'])
                ->update([
                    'valuation_group' => '其他業',
                    'valuation_group_pe' => 16.0,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tw_stock_valuation_groups')) {
            DB::table('tw_stock_valuation_groups')->where('group_name', '其他業')->delete();
        }
    }
};

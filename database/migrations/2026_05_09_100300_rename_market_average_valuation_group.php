<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tw_stock_valuation_groups')) {
            $otherExists = DB::table('tw_stock_valuation_groups')->where('group_name', '其他')->exists();
            if ($otherExists) {
                DB::table('tw_stock_valuation_groups')->where('group_name', '市場平均')->delete();
            } else {
                DB::table('tw_stock_valuation_groups')
                    ->where('group_name', '市場平均')
                    ->update([
                        'group_name' => '其他',
                        'average_pe' => 20.0,
                        'updated_at' => now(),
                    ]);
            }
        }

        if (Schema::hasTable('tw_stock_q1_financial_reports')) {
            DB::table('tw_stock_q1_financial_reports')
                ->where('valuation_group', '市場平均')
                ->update([
                    'valuation_group' => '其他',
                    'valuation_group_pe' => 20.0,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tw_stock_valuation_groups')) {
            DB::table('tw_stock_valuation_groups')
                ->where('group_name', '其他')
                ->where('average_pe', 20.0)
                ->update([
                    'group_name' => '市場平均',
                    'average_pe' => 25.0,
                    'updated_at' => now(),
                ]);
        }
    }
};

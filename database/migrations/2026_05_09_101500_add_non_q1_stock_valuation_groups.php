<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tw_stock_valuation_groups')) {
            return;
        }

        $now = now();

        DB::table('tw_stock_valuation_groups')->updateOrInsert(
            ['group_name' => '電子代工/EMS'],
            [
                'average_pe' => 20.0,
                'market_reference_pe' => 25.0,
                'source_date' => '2026-05-08',
                'source_note' => 'TWSE/TPEx 2026-05-08 official PE data plus 2026-05-09 non-Q1 stock business audit overrides.',
                'sort_order' => 45,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('tw_stock_valuation_groups')) {
            return;
        }

        DB::table('tw_stock_valuation_groups')
            ->where('group_name', '電子代工/EMS')
            ->delete();
    }
};

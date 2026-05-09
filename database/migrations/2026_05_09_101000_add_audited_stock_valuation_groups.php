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
        $sourceNote = 'TWSE/TPEx 2026-05-08 official PE data plus 2026-05-09 full Q1 stock business audit overrides.';
        $rows = [
            ['光學/鏡頭', 24.0, 72],
            ['電線電纜/重電', 18.0, 99],
            ['運動休閒/品牌消費', 16.0, 108],
            ['石化/油品', 12.0, 128],
        ];

        foreach ($rows as [$groupName, $averagePe, $sortOrder]) {
            DB::table('tw_stock_valuation_groups')->updateOrInsert(
                ['group_name' => $groupName],
                [
                    'average_pe' => $averagePe,
                    'market_reference_pe' => 25.0,
                    'source_date' => '2026-05-08',
                    'source_note' => $sourceNote,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('tw_stock_valuation_groups')) {
            return;
        }

        DB::table('tw_stock_valuation_groups')
            ->whereIn('group_name', [
                '光學/鏡頭',
                '電線電纜/重電',
                '運動休閒/品牌消費',
                '石化/油品',
            ])
            ->delete();
    }
};

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
        $sourceNote = 'TWSE/TPEx 2026-05-08 official PE data plus stock-specific business overrides for Q1 valuation groups.';
        $rows = [
            ['高速傳輸/連接器', 32.0, 55],
            ['電子設備/檢測', 27.0, 58],
            ['電子通路', 22.0, 59],
            ['光學/鏡頭', 24.0, 72],
            ['遊戲/數位內容', 24.0, 75],
            ['資訊服務/雲端', 20.0, 78],
            ['航太/國防', 30.0, 95],
            ['精密機械/自動化', 28.0, 98],
            ['電線電纜/重電', 18.0, 99],
            ['運動休閒/品牌消費', 16.0, 108],
            ['租賃/金融服務', 12.0, 125],
            ['石化/油品', 12.0, 128],
            ['交通運輸', 14.0, 145],
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

        DB::table('tw_stock_valuation_groups')
            ->where('group_name', '資訊服務/雲端')
            ->update(['average_pe' => 20.0, 'updated_at' => $now]);
    }

    public function down(): void
    {
        if (!Schema::hasTable('tw_stock_valuation_groups')) {
            return;
        }

        DB::table('tw_stock_valuation_groups')
            ->whereIn('group_name', [
                '高速傳輸/連接器',
                '電子設備/檢測',
                '電子通路',
                '光學/鏡頭',
                '遊戲/數位內容',
                '資訊服務/雲端',
                '航太/國防',
                '精密機械/自動化',
                '電線電纜/重電',
                '運動休閒/品牌消費',
                '租賃/金融服務',
                '石化/油品',
                '交通運輸',
            ])
            ->delete();
    }
};

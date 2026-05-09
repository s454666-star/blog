<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_stock_valuation_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('group_name', 32)->unique();
            $table->decimal('average_pe', 8, 4);
            $table->decimal('market_reference_pe', 8, 4)->default(25);
            $table->date('source_date')->nullable();
            $table->string('source_note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(999);
            $table->timestamps();
        });

        $now = now();
        $sourceNote = 'TWSE/TPEx 2026-05-08 official PE data: all median 21.84, <=80x trimmed average 25.01; group PE rounded by current Taiwan sector valuation.';
        $rows = [
            ['IC設計', 45.0, 10],
            ['記憶體/儲存', 38.0, 20],
            ['半導體製造/設備/材料', 32.0, 30],
            ['AI伺服器/電腦週邊', 34.0, 40],
            ['電子零組件/PCB', 30.0, 50],
            ['通信網路', 24.0, 60],
            ['光電/面板', 18.0, 70],
            ['生技醫療', 30.0, 80],
            ['汽車/電動車', 22.0, 90],
            ['綠能/電力', 22.0, 100],
            ['食品/觀光/消費', 18.0, 110],
            ['金融保險', 12.0, 120],
            ['營建資產', 13.0, 130],
            ['航運', 12.0, 140],
            ['原物料/傳產', 14.0, 150],
            ['市場平均', 25.0, 999],
        ];

        DB::table('tw_stock_valuation_groups')->insert(array_map(
            fn (array $row): array => [
                'group_name' => $row[0],
                'average_pe' => $row[1],
                'market_reference_pe' => 25.0,
                'source_date' => '2026-05-08',
                'source_note' => $sourceNote,
                'sort_order' => $row[2],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $rows,
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_stock_valuation_groups');
    }
};

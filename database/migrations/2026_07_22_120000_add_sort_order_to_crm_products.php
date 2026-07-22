<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_products', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('id');
        });

        DB::table('crm_products')->orderByDesc('created_at')->orderByDesc('id')->get(['id'])
            ->values()->each(fn ($product, int $index) => DB::table('crm_products')->where('id', $product->id)->update(['sort_order' => $index + 1]));
    }

    public function down(): void
    {
        Schema::table('crm_products', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

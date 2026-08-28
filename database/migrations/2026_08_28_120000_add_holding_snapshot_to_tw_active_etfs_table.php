<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_active_etfs', function (Blueprint $table): void {
            $table->date('holding_date')->nullable()->after('quote_fetched_at');
            $table->json('holding_items')->nullable()->after('holding_date');
            $table->string('holding_source')->nullable()->after('holding_items');
            $table->timestamp('holding_fetched_at')->nullable()->after('holding_source');
        });
    }

    public function down(): void
    {
        Schema::table('tw_active_etfs', function (Blueprint $table): void {
            $table->dropColumn([
                'holding_date',
                'holding_items',
                'holding_source',
                'holding_fetched_at',
            ]);
        });
    }
};

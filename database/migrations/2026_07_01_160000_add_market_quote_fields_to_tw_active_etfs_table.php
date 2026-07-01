<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tw_active_etfs', function (Blueprint $table): void {
            $table->string('exchange', 16)->nullable()->after('stock_name');
            $table->date('quote_date')->nullable()->after('fetched_at');
            $table->decimal('close_price', 12, 4)->nullable()->after('quote_date');
            $table->decimal('previous_close_price', 12, 4)->nullable()->after('close_price');
            $table->decimal('price_change_amount', 12, 4)->nullable()->after('previous_close_price');
            $table->decimal('price_change_percent', 8, 4)->nullable()->after('price_change_amount');
            $table->unsignedBigInteger('volume_lots')->nullable()->after('price_change_percent');
            $table->unsignedBigInteger('volume_shares')->nullable()->after('volume_lots');
            $table->unsignedBigInteger('trade_value')->nullable()->after('volume_shares');
            $table->unsignedInteger('transaction_count')->nullable()->after('trade_value');
            $table->string('quote_source')->nullable()->after('transaction_count');
            $table->json('quote_payload')->nullable()->after('quote_source');
            $table->timestamp('quote_fetched_at')->nullable()->after('quote_payload');

            $table->index(['is_active', 'exchange', 'stock_code'], 'idx_tw_active_etfs_active_exchange_code');
            $table->index(['is_active', 'trade_value'], 'idx_tw_active_etfs_active_trade_value');
        });
    }

    public function down(): void
    {
        Schema::table('tw_active_etfs', function (Blueprint $table): void {
            $table->dropIndex('idx_tw_active_etfs_active_exchange_code');
            $table->dropIndex('idx_tw_active_etfs_active_trade_value');
            $table->dropColumn([
                'exchange',
                'quote_date',
                'close_price',
                'previous_close_price',
                'price_change_amount',
                'price_change_percent',
                'volume_lots',
                'volume_shares',
                'trade_value',
                'transaction_count',
                'quote_source',
                'quote_payload',
                'quote_fetched_at',
            ]);
        });
    }
};

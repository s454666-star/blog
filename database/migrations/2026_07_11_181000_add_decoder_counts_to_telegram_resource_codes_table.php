<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('decoder_sent_count')->nullable()->after('forwarded_message_count');
            $table->unsignedSmallInteger('decoder_total_count')->nullable()->after('decoder_sent_count');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->dropColumn(['decoder_sent_count', 'decoder_total_count']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->unsignedTinyInteger('skip_reason')->nullable()->after('status');
            $table->dateTime('skipped_at')->nullable()->after('completed_at');
            $table->index(['status', 'skip_reason'], 'idx_telegram_resource_codes_skip');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->dropIndex('idx_telegram_resource_codes_skip');
            $table->dropColumn(['skip_reason', 'skipped_at']);
        });
    }
};

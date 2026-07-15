<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE telegram_resource_codes '
            . 'MODIFY code VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL'
        );

        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->index(
                ['code_type', 'status', 'available_at', 'id'],
                'idx_telegram_resource_codes_type_queue'
            );
        });
    }

    public function down(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->dropIndex('idx_telegram_resource_codes_type_queue');
        });

        $maxLength = (int) DB::table('telegram_resource_codes')
            ->selectRaw('MAX(CHAR_LENGTH(code)) AS max_length')
            ->value('max_length');

        if ($maxLength > 40) {
            throw new RuntimeException('Cannot shrink telegram_resource_codes.code while values exceed 40 characters.');
        }

        DB::statement(
            'ALTER TABLE telegram_resource_codes '
            . 'MODIFY code CHAR(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL'
        );
    }
};

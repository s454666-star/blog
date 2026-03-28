<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_filestore_sessions', function (Blueprint $table): void {
            if (!Schema::hasColumn('telegram_filestore_sessions', 'source_token')) {
                $table->string('source_token')->nullable()->after('public_token');
                $table->unique('source_token', 'telegram_filestore_sessions_source_token_unique');
            }
        });

        Schema::table('telegram_filestore_files', function (Blueprint $table): void {
            if (!Schema::hasColumn('telegram_filestore_files', 'source_token')) {
                $table->string('source_token')->nullable()->after('file_unique_id');
                $table->index('source_token', 'telegram_filestore_files_source_token_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('telegram_filestore_files', function (Blueprint $table): void {
            if (Schema::hasColumn('telegram_filestore_files', 'source_token')) {
                $table->dropIndex('telegram_filestore_files_source_token_index');
                $table->dropColumn('source_token');
            }
        });

        Schema::table('telegram_filestore_sessions', function (Blueprint $table): void {
            if (Schema::hasColumn('telegram_filestore_sessions', 'source_token')) {
                $table->dropUnique('telegram_filestore_sessions_source_token_unique');
                $table->dropColumn('source_token');
            }
        });
    }
};

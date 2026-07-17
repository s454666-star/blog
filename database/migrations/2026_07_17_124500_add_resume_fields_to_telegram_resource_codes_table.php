<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->unsignedSmallInteger('last_completed_page')->nullable()->after('decoder_total_count');
            $table->unsignedSmallInteger('resume_from_page')->nullable()->after('last_completed_page');
            $table->unsignedSmallInteger('decoder_total_pages')->nullable()->after('resume_from_page');
            $table->string('resume_bot_username', 64)->nullable()->after('decoder_total_pages');
            $table->timestamp('paused_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_resource_codes', function (Blueprint $table): void {
            $table->dropColumn([
                'last_completed_page',
                'resume_from_page',
                'decoder_total_pages',
                'resume_bot_username',
                'paused_at',
            ]);
        });
    }
};

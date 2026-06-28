<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawler_profile_candidates', function (Blueprint $table): void {
            $table->text('chat_url')->nullable()->after('profile_url');
        });
    }

    public function down(): void
    {
        Schema::table('crawler_profile_candidates', function (Blueprint $table): void {
            $table->dropColumn('chat_url');
        });
    }
};

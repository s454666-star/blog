<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawler_profile_candidates', function (Blueprint $table): void {
            $table->unsignedSmallInteger('height')->nullable()->after('chat_url');
            $table->unsignedSmallInteger('weight')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('crawler_profile_candidates', function (Blueprint $table): void {
            $table->dropColumn(['height', 'weight']);
        });
    }
};

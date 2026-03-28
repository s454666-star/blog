<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('dialogues', 'is_sync')) {
            return;
        }

        Schema::table('dialogues', function (Blueprint $table): void {
            $table->boolean('is_sync')->default(false);
            $table->index(['is_sync', 'id'], 'dialogues_is_sync_id_idx');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('dialogues', 'is_sync')) {
            return;
        }

        Schema::table('dialogues', function (Blueprint $table): void {
            $table->dropIndex('dialogues_is_sync_id_idx');
            $table->dropColumn('is_sync');
        });
    }
};

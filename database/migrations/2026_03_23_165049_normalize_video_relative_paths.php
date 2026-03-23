<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->normalizePathColumn('video_master', 'video_path');
        $this->normalizePathColumn('video_screenshots', 'screenshot_path');
        $this->normalizePathColumn('video_face_screenshots', 'face_image_path');
        $this->normalizePathColumn('video_features', 'video_path');
        $this->normalizeDirectoryColumn('video_features', 'directory_path');
        $this->normalizePathColumn('video_feature_frames', 'screenshot_path');
        $this->normalizePathColumn('external_video_duplicate_frames', 'screenshot_path');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irreversible data cleanup. Leave normalized paths as-is.
    }

    private function normalizePathColumn(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement("
            UPDATE `{$table}`
             SET `{$column}` = TRIM(BOTH '/' FROM REPLACE(REPLACE(REPLACE(`{$column}`, CHAR(92), '/'), '//', '/'), '//', '/'))
             WHERE `{$column}` IS NOT NULL
               AND (
                    INSTR(`{$column}`, CHAR(92)) > 0
                 OR LEFT(`{$column}`, 1) = '/'
                 OR RIGHT(`{$column}`, 1) = '/'
                 OR INSTR(`{$column}`, '//') > 0
               )",
        );
    }

    private function normalizeDirectoryColumn(string $table, string $column): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }

        DB::statement("
            UPDATE `{$table}`
             SET `{$column}` = NULLIF(
                    TRIM(BOTH '/' FROM REPLACE(REPLACE(REPLACE(`{$column}`, CHAR(92), '/'), '//', '/'), '//', '/')),
                    ''
                 )
             WHERE `{$column}` IS NOT NULL
               AND (
                    INSTR(`{$column}`, CHAR(92)) > 0
                 OR LEFT(`{$column}`, 1) = '/'
                 OR RIGHT(`{$column}`, 1) = '/'
                 OR INSTR(`{$column}`, '//') > 0
               )",
        );
    }
};

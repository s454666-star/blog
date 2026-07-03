<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE yuanta_portfolio_daily_snapshots MODIFY captured_at DATETIME NULL, MODIFY queried_at DATETIME NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE yuanta_portfolio_daily_snapshots MODIFY captured_at TIMESTAMP NULL, MODIFY queried_at TIMESTAMP NULL');
    }
};

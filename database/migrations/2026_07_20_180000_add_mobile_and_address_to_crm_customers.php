<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->string('mobile', 50)->nullable()->after('phone');
            $table->string('address')->nullable()->after('mobile');
        });
    }

    public function down(): void
    {
        Schema::table('crm_customers', function (Blueprint $table) {
            $table->dropColumn(['mobile', 'address']);
        });
    }
};

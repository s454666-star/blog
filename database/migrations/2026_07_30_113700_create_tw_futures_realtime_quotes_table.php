<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tw_futures_realtime_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('symbol', 32)->unique();
            $table->decimal('price', 12, 4);
            $table->unsignedBigInteger('volume')->nullable();
            $table->dateTime('quote_at');
            $table->dateTime('written_at', 3);
            $table->dateTime('bar_started_at');
            $table->decimal('bar_open', 12, 4);
            $table->decimal('bar_high', 12, 4);
            $table->decimal('bar_low', 12, 4);
            $table->string('source');
            $table->string('auth_mode', 32);
            $table->json('source_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tw_futures_realtime_quotes');
    }
};

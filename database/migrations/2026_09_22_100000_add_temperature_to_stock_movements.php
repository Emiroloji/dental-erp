<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Soğuk zincir (Aşama 26): girişte ölçülen sıcaklık ve aralık dışı kabul
     * gerekçesi hareket kaydıyla birlikte, değiştirilemez şekilde saklanır.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('temperature', 5, 1)->nullable()->after('reason_code');
            $table->string('temperature_note', 500)->nullable()->after('temperature');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['temperature', 'temperature_note']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * proje.md Bölüm 6: depo açıklaması ve depo sorumlusu ataması — bir
     * personel birden fazla depodan sorumlu olabilir (çoka-çok).
     */
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name');
        });

        Schema::create('warehouse_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['warehouse_id', 'user_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('warehouse_user');

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};

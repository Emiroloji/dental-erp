<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Organizasyon (tenant) ayarları — Ayarlar ekranı (proje.md Bölüm 13).
     * İlk ayar: SKT'si geçmiş lotun kullanımında "sadece uyar" / "tamamen
     * engelle" seçimi (proje.md Bölüm 7, kurallar.md Bölüm 1). null veya eksik
     * anahtar = varsayılan değer (App\Domain\Organization\Support).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->jsonb('settings')->nullable()->after('max_storage_mb');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aşama 20 — barkodla hızlı işlem: okutulan barkod organizasyon içinde
     * aranır. Benzersizlik ekranda doğrulanır (mevcut veride tekrar olabileceği
     * için veritabanı düzeyinde unique değil; tekrar varsa okutma açıkça hata verir).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->index(['organization_id', 'barcode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'barcode']);
        });
    }
};

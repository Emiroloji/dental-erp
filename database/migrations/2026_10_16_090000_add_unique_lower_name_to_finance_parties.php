<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cari adı organizasyon içinde büyük/küçük harften bağımsız tekildir (Aşama 35
 * son incelemesi: uygulama kontrolü eşzamanlı iki kaydı engelleyemiyordu).
 * İfade indeksi PostgreSQL ve SQLite'ta aynı sözdizimiyle çalışır.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX finance_parties_org_lower_name_unique ON finance_parties (organization_id, LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX finance_parties_org_lower_name_unique');
    }
};

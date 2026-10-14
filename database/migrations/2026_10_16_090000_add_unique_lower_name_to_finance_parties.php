<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cari adı organizasyon içinde büyük/küçük harften bağımsız tekildir (Aşama 35
 * son incelemesi: uygulama kontrolü eşzamanlı iki kaydı engelleyemiyordu).
 * İfade indeksi PostgreSQL ve SQLite'ta aynı sözdizimiyle çalışır. Canlıda zaten
 * büyük/küçük harf farkıyla yinelenen adlar varsa indeks kurulumu (ve dağıtım)
 * düşmesin diye önce yinelenenler "Ad (id)" olarak yeniden adlandırılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: yeniden adlandırma ile indeks kurulumu arasında canlı bir ekleme
        // yeni bir yinelenen yaratmasın diye yazmaları (SELECT hariç) engelle.
        // Göç işlemi bir transaction içinde çalışır; kilit sonunda kendiliğinden kalkar.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('LOCK TABLE finance_parties IN SHARE ROW EXCLUSIVE MODE');
        }

        $groups = DB::table('finance_parties')
            ->selectRaw('organization_id, LOWER(name) AS k, MIN(id) AS keep_id')
            ->groupByRaw('organization_id, LOWER(name)')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $duplicates = DB::table('finance_parties')
                ->where('organization_id', $group->organization_id)
                ->whereRaw('LOWER(name) = ?', [$group->k])
                ->where('id', '!=', $group->keep_id)
                ->get(['id', 'name']);

            foreach ($duplicates as $row) {
                $newName = mb_substr($row->name, 0, 240).' ('.$row->id.')';
                DB::table('finance_parties')->where('id', $row->id)->update(['name' => $newName]);
                Log::warning('Yinelenen cari adı yeniden adlandırıldı.', [
                    'organization_id' => $group->organization_id, 'party_id' => $row->id, 'old' => $row->name, 'new' => $newName,
                ]);
            }
        }

        DB::statement('CREATE UNIQUE INDEX finance_parties_org_lower_name_unique ON finance_parties (organization_id, LOWER(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS finance_parties_org_lower_name_unique');
    }
};

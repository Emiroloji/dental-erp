<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Laravel'in standart bildirim şeması data'yı text tutar. StockAlertService
     * ise data->product_id / data->level üzerinden JSON sorgusu yapar; bu
     * PostgreSQL'de text kolonda "operator does not exist: text ->> unknown"
     * hatası verir (SQLite metin üzerinde JSON sorgusuna izin verdiği için
     * testlerde görünmüyordu). PostgreSQL'de kolon jsonb'ye çevrilir.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }
};

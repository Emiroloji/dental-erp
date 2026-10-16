<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Uygulama saat dilimi UTC'den Europe/Istanbul'a geçti (16.10.2026). Tarihler
 * saat dilimi bilgisi olmadan (timestamp without time zone) saklandığı için
 * şimdiye kadar UTC yazılmış ve ekranlarda 3 saat geri görünmüştü. Türkiye
 * 2016'dan beri yaz saati uygulamadığından fark her zaman tam 3 saattir.
 *
 * Yalnızca şu anki UTC saatinden eski değerler kaydırılır: deploy sırasında
 * yeni kodun (İstanbul saatiyle) yazdığı bir kayıt UTC'nin 3 saat önündedir
 * ve iki kez kaydırılmaz. Şemada gelecek tarihli zaman sütunu yoktur.
 *
 * Yalnızca PostgreSQL (canlı ortam). Testlerdeki SQLite veritabanında
 * kaydırılacak eski veri olmadığı için atlanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('+', "<= (now() at time zone 'utc')");
    }

    public function down(): void
    {
        $this->shift('-', "<= (now() at time zone 'utc') + interval '3 hours'");
    }

    private function shift(string $direction, string $guard): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $columns = DB::select("
            select table_name, column_name
            from information_schema.columns
            where table_schema = current_schema()
              and data_type = 'timestamp without time zone'
            order by table_name, column_name
        ");

        foreach ($columns as $column) {
            DB::statement(sprintf(
                'update "%1$s" set "%2$s" = "%2$s" %3$s interval \'3 hours\' where "%2$s" is not null and "%2$s" %4$s',
                $column->table_name,
                $column->column_name,
                $direction,
                $guard,
            ));
        }
    }
};

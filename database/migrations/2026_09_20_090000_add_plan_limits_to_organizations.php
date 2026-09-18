<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Faz 3 — paket limitleri (proje.md Bölüm 12). Limitler paketten gelir
     * (App\Domain\Platform\Support\Plan); bu kolonlar yalnızca Platform
     * Sahibi'nin organizasyona özel girdiği değerlerdir (ör. Kurumsal paketin
     * "Özel" depolaması). null = paketin varsayılanı geçerli.
     *
     * Depolama kullanımı yüklenen belgelerin boyutundan hesaplanır; bu yüzden
     * satın alma teslim belgelerinin boyutu da saklanır.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->unsignedInteger('max_branches')->nullable()->after('plan');
            $table->unsignedInteger('max_users')->nullable()->after('max_branches');
            $table->unsignedInteger('max_storage_mb')->nullable()->after('max_users');
        });

        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->unsignedBigInteger('document_size')->nullable()->after('document_name');
        });

        // Mevcut belgelerin boyutu diskten okunur.
        DB::table('purchase_receipts')->whereNotNull('document_path')->orderBy('id')->each(function ($receipt) {
            $disk = Storage::disk('local');

            if ($disk->exists($receipt->document_path)) {
                DB::table('purchase_receipts')->where('id', $receipt->id)->update(['document_size' => $disk->size($receipt->document_path)]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropColumn('document_size');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['max_branches', 'max_users', 'max_storage_mb']);
        });
    }
};

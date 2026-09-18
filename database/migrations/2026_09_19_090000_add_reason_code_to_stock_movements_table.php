<?php

use App\Domain\Stock\Support\StockOutReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * reason serbest metindir ("Klinik içi kullanım: not"); raporlar bu metne
     * güvenemez. reason_code, çıkışın yapılandırılmış nedenini (StockOutReason)
     * tutar — "kullanım" metrikleri yalnızca bu koda bakar.
     *
     * Mevcut çıkış hareketleri, Stok Çıkışı ekranının yazdığı etiket önekinden
     * geriye dönük doldurulur. Eşleşmeyenler NULL kalır ve kullanım sayılmaz.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('reason_code')->nullable()->after('reason');
            $table->index(['type', 'reason_code']);
        });

        foreach (StockOutReason::cases() as $reason) {
            DB::table('stock_movements')
                ->where('type', 'out')
                ->whereNull('reason_code')
                ->where(function ($query) use ($reason) {
                    $query->where('reason', $reason->label())
                        ->orWhere('reason', 'like', $reason->label().': %');
                })
                ->update(['reason_code' => $reason->value]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['type', 'reason_code']);
            $table->dropColumn('reason_code');
        });
    }
};

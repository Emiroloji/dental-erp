<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Ürün bazlı uyarı eşiği (fazlar-adimlar.md Aşama 29.1): her ürün kendi
     * sarı/kırmızı eşiğini taşır. Mod "gün bazlı" ise eşikler SKT'ye kalan gün
     * sayısını, "miktar bazlı" ise kalan stok adedini ifade eder. Üçü de
     * nullable: boş bırakılan üründe proje.md Bölüm 7'deki eski sabit
     * varsayılan (son 10 adet sarı / son 5 adet kırmızı) uygulanmaya devam eder.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('alert_mode', 20)->nullable()->after('max_stock');
            $table->decimal('alert_low_threshold', 12, 2)->nullable()->after('alert_mode');
            $table->decimal('alert_critical_threshold', 12, 2)->nullable()->after('alert_low_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['alert_mode', 'alert_low_threshold', 'alert_critical_threshold']);
        });
    }
};

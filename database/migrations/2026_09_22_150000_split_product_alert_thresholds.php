<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aşama 29.1'in genişletilmesi: bir ürün artık hem miktara hem son
     * kullanma tarihine göre aynı anda izlenebilir ("ikisi birden" modu).
     * Tek bir sarı/kırmızı eşik çifti bunu taşıyamadığı için eşikler iki
     * eksene ayrıldı: miktar eşikleri (adet) ve SKT eşikleri (gün).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('alert_quantity_low', 12, 2)->nullable()->after('alert_mode');
            $table->decimal('alert_quantity_critical', 12, 2)->nullable()->after('alert_quantity_low');
            $table->unsignedSmallInteger('alert_expiry_low_days')->nullable()->after('alert_quantity_critical');
            $table->unsignedSmallInteger('alert_expiry_critical_days')->nullable()->after('alert_expiry_low_days');
        });

        // Tek eşik çiftinde duran değerler ait oldukları eksene taşınır.
        DB::table('products')->where('alert_mode', 'quantity')->update([
            'alert_quantity_low' => DB::raw('alert_low_threshold'),
            'alert_quantity_critical' => DB::raw('alert_critical_threshold'),
        ]);

        DB::table('products')->where('alert_mode', 'days')->update([
            'alert_expiry_low_days' => DB::raw('alert_low_threshold'),
            'alert_expiry_critical_days' => DB::raw('alert_critical_threshold'),
        ]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['alert_low_threshold', 'alert_critical_threshold']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('alert_low_threshold', 12, 2)->nullable()->after('alert_mode');
            $table->decimal('alert_critical_threshold', 12, 2)->nullable()->after('alert_low_threshold');
        });

        DB::table('products')->where('alert_mode', 'quantity')->update([
            'alert_low_threshold' => DB::raw('alert_quantity_low'),
            'alert_critical_threshold' => DB::raw('alert_quantity_critical'),
        ]);

        DB::table('products')->where('alert_mode', 'days')->update([
            'alert_low_threshold' => DB::raw('alert_expiry_low_days'),
            'alert_critical_threshold' => DB::raw('alert_expiry_critical_days'),
        ]);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['alert_quantity_low', 'alert_quantity_critical', 'alert_expiry_low_days', 'alert_expiry_critical_days']);
        });
    }
};

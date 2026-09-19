<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * İlaç ve özel medikal ürün genişletmesi (proje.md Bölüm 5, Faz 4 —
     * Aşama 26): ÜTS/GTIN/ruhsat alanları, saklama koşulu, soğuk zincir
     * sıcaklık aralığı, kontrollü ürün işareti ve birim bazında seri takibi.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('gtin', 14)->nullable()->after('barcode');
            $table->string('uts_number')->nullable()->after('gtin');
            $table->string('license_number')->nullable()->after('uts_number');
            $table->string('manufacturer')->nullable()->after('license_number');
            $table->string('storage_condition')->nullable()->after('manufacturer');
            $table->boolean('cold_chain')->default(false)->after('storage_condition');
            $table->decimal('storage_min_temp', 5, 1)->nullable()->after('cold_chain');
            $table->decimal('storage_max_temp', 5, 1)->nullable()->after('storage_min_temp');
            $table->boolean('is_controlled')->default(false)->after('storage_max_temp');
            $table->boolean('tracks_serials')->default(false)->after('is_controlled');

            $table->index(['organization_id', 'gtin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'gtin']);
            $table->dropColumn(['gtin', 'uts_number', 'license_number', 'manufacturer', 'storage_condition', 'cold_chain', 'storage_min_temp', 'storage_max_temp', 'is_controlled', 'tracks_serials']);
        });
    }
};

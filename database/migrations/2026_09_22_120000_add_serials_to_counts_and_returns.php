<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seri takibinin iş akışları (Aşama 26): sayımda seri takipli lotun fiilen
     * bulunan serileri; tedarikçiye iadede hangi birimlerin iade edildiği.
     */
    public function up(): void
    {
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->jsonb('counted_serials')->nullable()->after('counted_quantity');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->jsonb('serial_numbers')->nullable()->after('quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropColumn('serial_numbers');
        });

        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->dropColumn('counted_serials');
        });
    }
};

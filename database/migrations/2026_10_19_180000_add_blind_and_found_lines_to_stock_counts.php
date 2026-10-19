<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aşama 58: kör sayım — sayan, sayım bitene kadar sistem miktarını görmez;
     * blind_revealed_at "Sayımı Bitir" anıdır, sonrasında sayılan miktarlar
     * kilitlidir. added_during_count: sayım başladıktan sonra elle eklenen
     * (rafta bulunup listede olmayan) lot satırı.
     */
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->boolean('blind')->default(false)->after('note');
            $table->timestamp('blind_revealed_at')->nullable()->after('blind');
        });

        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->boolean('added_during_count')->default(false)->after('system_quantity');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->dropColumn('added_during_count');
        });

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropColumn(['blind', 'blind_revealed_at']);
        });
    }
};

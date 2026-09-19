<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Birim bazında seri numarası takibi (Aşama 26). stock_serials her birimin
     * GÜNCEL lotunu ve durumunu tutar; stock_movement_serials hangi hareketin
     * hangi seriyi taşıdığını değiştirilemez şekilde saklar (geçmiş buradan).
     * Değişmez: seri takipli üründe lot miktarı = o lotta "in_stock" seri sayısı.
     */
    public function up(): void
    {
        Schema::create('stock_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('stock_lots')->cascadeOnDelete();
            $table->string('serial_no', 100);
            $table->string('status', 20);
            $table->timestamps();

            $table->unique(['product_id', 'serial_no']);
            $table->index(['lot_id', 'status']);
            $table->index(['organization_id', 'serial_no']);
        });

        Schema::create('stock_movement_serials', function (Blueprint $table) {
            $table->foreignId('stock_movement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_serial_id')->constrained()->cascadeOnDelete();

            $table->primary(['stock_movement_id', 'stock_serial_id']);
            $table->index('stock_serial_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movement_serials');
        Schema::dropIfExists('stock_serials');
    }
};

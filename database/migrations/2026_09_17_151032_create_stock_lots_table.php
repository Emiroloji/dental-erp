<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('lot_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->decimal('quantity', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id']);
            $table->index('expiry_date');
            $table->unique(['product_id', 'warehouse_id', 'lot_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_lots');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * fazlar-adimlar.md Aşama 14 / proje.md Bölüm 10: tedarikçiye iade. Bir
     * iade tek bir lottan yapılır (iade edilen ürün/lot, miktar, neden, ilgili
     * sipariş/fatura, durum). Stok yalnızca "Kargoya Verildi" anında, lot
     * üzerinden StockMovementService ile düşülür; hareket iadeye bağlıdır.
     */
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_lot_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number')->nullable();
            $table->decimal('quantity', 12, 2);
            // damaged, defective, wrong_shipment, expiry_issue, other
            $table->string('reason');
            $table->string('reason_note')->nullable();
            // requested, approved, shipped, supplier_approved, completed, rejected
            $table->string('status')->default('requested');
            $table->string('supplier_response', 500)->nullable();
            $table->string('credit_note_number')->nullable();
            $table->decimal('credit_amount', 12, 2)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('warehouse_id');
        });

        Schema::create('return_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('return_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('return_events');
        Schema::dropIfExists('returns');
    }
};

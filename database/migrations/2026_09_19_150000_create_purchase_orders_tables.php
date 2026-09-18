<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * fazlar-adimlar.md Aşama 12 / proje.md Bölüm 9. Bir sipariş tek bir
     * tedarikçiye ve teslim alınacak tek bir depoya aittir; çok kalemlidir.
     * Her teslim alma (purchase_receipts) sipariş satırlarına bağlanır ve
     * oluşturduğu stok girişini (stock_movement_id) işaret eder.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            // draft, pending_approval, approved, rejected, ordered, partially_received, completed, cancelled
            $table->string('status')->default('draft');
            $table->date('expected_delivery_date')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('supplier_id');
            $table->index('warehouse_id');
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('received_quantity', 12, 2)->default(0);
            $table->timestamps();

            $table->index('purchase_order_id');
        });

        Schema::create('purchase_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('purchase_order_id');
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number')->nullable();
            $table->string('delivery_note_number')->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('purchase_order_id');
        });

        Schema::create('purchase_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 2);
            $table->string('lot_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();

            $table->index('purchase_receipt_id');
            $table->index('purchase_order_line_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_receipt_lines');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_events');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};

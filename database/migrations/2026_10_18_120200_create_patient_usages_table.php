<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_movement_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_lot_id')->constrained('stock_lots')->restrictOnDelete();
            $table->foreignId('stock_serial_id')->nullable()->constrained('stock_serials')->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->date('used_on');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'patient_id']);
            $table->index('stock_movement_id');
        });

        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->foreignId('patient_usage_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            $table->string('source_notification_id', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_usage_id');
        });

        Schema::dropIfExists('patient_usages');
    }
};

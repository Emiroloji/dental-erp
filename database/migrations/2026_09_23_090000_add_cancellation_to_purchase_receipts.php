<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aşama 30 — teslim alma düzeltmesi.
 *
 * Yanlış girilen bir teslim alma, kayıt silinerek değil iptal edilerek
 * düzeltilir: fatura/irsaliye kaydı ve stok hareketleri denetim izinde kalmalı,
 * geri alma da ayrı bir hareket olarak görünmelidir (kurallar.md — stok
 * hareketleri geriye dönük değiştirilemez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('received_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->string('cancellation_reason')->nullable()->after('cancelled_by');

            $table->index('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_receipts', function (Blueprint $table) {
            $table->dropIndex(['cancelled_at']);
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
    }
};

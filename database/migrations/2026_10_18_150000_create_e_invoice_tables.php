<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e_invoice_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('environment', 16)->default('test');
            $table->text('api_key');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('e_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider_uuid', 64);
            $table->string('invoice_number', 32);
            $table->string('profile', 32)->nullable();
            $table->string('type', 32)->nullable();
            $table->date('issue_date');
            $table->string('sender_tax_number', 16)->nullable();
            $table->string('sender_name')->nullable();
            $table->string('currency', 3)->default('TRY');
            $table->bigInteger('line_extension');
            $table->bigInteger('tax_exclusive');
            $table->bigInteger('tax_total');
            $table->bigInteger('payable');
            $table->string('xml_path');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('finance_obligation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'provider_uuid']);
            $table->index(['organization_id', 'issue_date']);
            $table->index(['organization_id', 'invoice_number']);
        });

        Schema::create('e_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('e_invoice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('name');
            $table->string('seller_code', 64)->nullable();
            $table->decimal('quantity', 14, 4);
            $table->string('unit_code', 8)->nullable();
            $table->bigInteger('unit_price');
            $table->bigInteger('line_amount');
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('e_invoice_line_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('e_invoice_line_id')->constrained()->cascadeOnDelete();
            $table->string('scheme', 16);
            $table->string('gtin', 32)->nullable();
            $table->string('lot', 64)->nullable();
            $table->string('serial', 64)->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('raw');

            $table->index('gtin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e_invoice_line_identities');
        Schema::dropIfExists('e_invoice_lines');
        Schema::dropIfExists('e_invoices');
        Schema::dropIfExists('e_invoice_connections');
    }
};

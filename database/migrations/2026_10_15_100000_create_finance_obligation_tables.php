<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 Finans — cari ve vadeler (Aşama 35). Yükümlülük "beklenen para"dır;
 * ödenen tutar saklanmaz, defterdeki payment kayıtlarından türetilir.
 * Yalnızca tablo/kolon ekler; mevcut veriye dokunmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 16);
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number', 20)->nullable();
            $table->unsignedSmallInteger('default_term_days')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('finance_recurrings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->string('name');
            $table->bigInteger('amount');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories');
            $table->foreignId('party_id')->nullable()->constrained('finance_parties');
            $table->string('frequency', 16);
            $table->unsignedTinyInteger('day');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->date('next_due_on');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['status', 'next_due_on']);
        });

        Schema::create('finance_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 16);
            $table->foreignId('party_id')->nullable()->constrained('finance_parties');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories');
            $table->bigInteger('amount');
            $table->date('due_on');
            $table->string('description', 500);
            $table->string('document_no', 100)->nullable();
            $table->foreignId('recurring_id')->nullable()->constrained('finance_recurrings');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->string('status', 16)->default('open');
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['recurring_id', 'due_on']);
            $table->index(['organization_id', 'status', 'due_on']);
            $table->index('party_id');
        });

        Schema::table('finance_entries', function (Blueprint $table) {
            $table->foreignId('party_id')->nullable()->after('category_id')->constrained('finance_parties');
            $table->foreignId('obligation_id')->nullable()->after('party_id')->constrained('finance_obligations');

            $table->index('obligation_id');
            $table->index('party_id');
        });
    }

    public function down(): void
    {
        Schema::table('finance_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('obligation_id');
            $table->dropConstrainedForeignId('party_id');
        });

        Schema::dropIfExists('finance_obligations');
        Schema::dropIfExists('finance_recurrings');
        Schema::dropIfExists('finance_parties');
    }
};

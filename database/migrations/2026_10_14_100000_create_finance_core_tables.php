<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faz 5 Finans çekirdeği (Aşama 34). Tutarlar kuruş cinsinden bigint.
 * finance_entries yalnızca eklenen bir defterdir; bakiye hiçbir tabloda saklanmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 16);
            $table->string('iban', 34)->nullable();
            $table->bigInteger('opening_balance')->default(0);
            $table->date('opening_date');
            $table->string('currency', 3)->default('TRY');
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('finance_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('direction', 16);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['organization_id', 'direction', 'name']);
        });

        Schema::create('finance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts');
            $table->string('type', 24);
            $table->smallInteger('direction');
            $table->bigInteger('amount');
            $table->date('occurred_on');
            $table->foreignId('category_id')->nullable()->constrained('finance_categories');
            $table->foreignId('branch_id')->nullable()->constrained('branches');
            $table->uuid('transfer_group')->nullable();
            $table->foreignId('reverses_entry_id')->nullable()->unique()->constrained('finance_entries');
            $table->string('description', 500)->nullable();
            $table->string('document_no', 100)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'account_id', 'occurred_on']);
            $table->index(['organization_id', 'occurred_on']);
            $table->index('transfer_group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('finance_categories');
        Schema::dropIfExists('finance_accounts');
    }
};

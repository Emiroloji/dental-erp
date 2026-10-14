<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts');
            $table->date('statement_date');
            $table->bigInteger('statement_balance');
            $table->bigInteger('system_balance');
            $table->bigInteger('difference');
            $table->foreignId('adjustment_entry_id')->nullable()->constrained('finance_entries');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'account_id', 'statement_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_reconciliations');
    }
};

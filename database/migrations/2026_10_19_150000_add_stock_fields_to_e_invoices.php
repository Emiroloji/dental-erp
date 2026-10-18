<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e_invoices', function (Blueprint $table) {
            $table->timestamp('stocked_at')->nullable()->after('finance_obligation_id');
            $table->foreignId('stocked_by')->nullable()->after('stocked_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('e_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stocked_by');
            $table->dropColumn('stocked_at');
        });
    }
};

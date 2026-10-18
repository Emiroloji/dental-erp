<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedBigInteger('uts_institution_no')->nullable()->after('tax_number');
        });

        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->foreignId('supplier_return_id')->nullable()->after('patient_usage_id')->constrained('returns')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_return_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('uts_institution_no');
        });
    }
};

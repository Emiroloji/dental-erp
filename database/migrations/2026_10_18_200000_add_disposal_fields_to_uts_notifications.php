<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->foreignId('stock_movement_id')->nullable()->after('supplier_return_id')->constrained()->restrictOnDelete();
            $table->json('details')->nullable()->after('message');
        });
    }

    public function down(): void
    {
        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_movement_id');
            $table->dropColumn('details');
        });
    }
};

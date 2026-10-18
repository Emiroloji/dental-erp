<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_usages', function (Blueprint $table) {
            $table->date('removed_on')->nullable()->after('used_on');
            $table->string('removal_reason', 32)->nullable()->after('removed_on');
            $table->string('removal_note', 50)->nullable()->after('removal_reason');
        });
    }

    public function down(): void
    {
        Schema::table('patient_usages', function (Blueprint $table) {
            $table->dropColumn(['removed_on', 'removal_reason', 'removal_note']);
        });
    }
};

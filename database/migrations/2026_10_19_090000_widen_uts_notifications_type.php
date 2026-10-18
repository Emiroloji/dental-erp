<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 'almak_istemiyorum' (17 karakter) 16 karakterlik type sütununa sığmıyordu;
 * PostgreSQL insert'i reddeder, SQLite sınırı uygulamadığı için testlerde
 * görünmemişti (Aşama 53'te PostgreSQL denemesiyle bulundu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->string('type', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('uts_notifications', function (Blueprint $table) {
            $table->string('type', 16)->change();
        });
    }
};

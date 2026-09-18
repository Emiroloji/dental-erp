<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aşama 18 — Platform Yönetici Paneli. proje.md Bölüm 2/4: organizasyon
     * iletişim bilgileri; Ana Klinik Sahibi için geçici şifreyle açılan hesap
     * ilk girişte şifresini değiştirir (must_change_password).
     *
     * organizations.status artık üç değer alır: active, read_only, passive
     * (salt-okunur ve pasif, Platform Sahibi'nin manuel kararıdır).
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('contact_email')->nullable()->after('name');
            $table->string('contact_phone', 50)->nullable()->after('contact_email');
            $table->string('address')->nullable()->after('contact_phone');
            $table->index('plan');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['plan']);
            $table->dropColumn(['contact_email', 'contact_phone', 'address']);
        });
    }
};

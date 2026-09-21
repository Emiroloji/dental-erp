<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tanıtım sitesindeki talep formundan gelen kayıtlar (fazlar-adimlar.md
     * Aşama 29.2). Bilerek hiçbir organizasyona bağlı değil: form doldurmak
     * otomatik hesap/organizasyon açmaz (proje.md Bölüm 2). Platform Sahibi
     * talebi görür, elle iletişime geçer, uygun görürse organizasyonu kendi
     * panelinden açar.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('clinic_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('note')->nullable();
            $table->string('status', 20)->default('new');
            $table->string('ip_address', 45)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

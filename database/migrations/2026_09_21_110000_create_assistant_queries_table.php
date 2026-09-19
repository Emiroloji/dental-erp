<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Rapor Asistanı sorguları (Aşama 25): organizasyon başına günlük/aylık
     * limit bu tablodan sayılır; kullanıcı kendi soru geçmişini görür.
     */
    public function up(): void
    {
        Schema::create('assistant_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->string('provider', 50);
            $table->string('status', 20)->default('pending');
            $table->jsonb('intent')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_queries');
    }
};

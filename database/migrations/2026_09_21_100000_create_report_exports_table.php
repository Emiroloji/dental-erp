<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Kuyruğa alınan rapor dışa aktarımları (mimari.md Bölüm 6: rapor üretimi
     * arka planda). Dosya hazır olunca isteyen kullanıcıya bildirim gider;
     * dosyayı yalnızca isteyen kullanıcı indirebilir.
     */
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report');
            $table->string('format', 10);
            $table->jsonb('parameters')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};

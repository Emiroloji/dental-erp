<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Standart Laravel bildirim şeması kullanılır (uuid id + notifiable morph +
     * json data + read_at) — User modeli Aşama 1'den beri Notifiable trait'ini
     * kullanıyor, bu tabloyu tam olarak bekliyor. Aşama 15'te eklenecek transfer/
     * satın alma/sayım bildirimleri de aynı tabloyu, kendi Notification
     * sınıflarıyla paylaşacak; her domain kendi bildirim türünü tanımlar.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

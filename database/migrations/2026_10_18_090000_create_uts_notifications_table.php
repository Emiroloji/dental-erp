<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uts_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('source_notification_id', 64);
            $table->string('uts_notification_id', 64)->nullable();
            $table->string('status', 16);
            $table->string('environment', 16);
            $table->string('uno', 32);
            $table->string('product_name')->nullable();
            $table->string('lot', 32)->nullable();
            $table->string('serial', 32)->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedBigInteger('sender_code')->nullable();
            $table->string('sender_name')->nullable();
            $table->string('document_no', 64)->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'source_notification_id']);
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uts_notifications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('uts_pending_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('notification_id', 64);
            $table->timestamp('first_seen_at');

            $table->unique(['organization_id', 'notification_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uts_pending_seen');
    }
};

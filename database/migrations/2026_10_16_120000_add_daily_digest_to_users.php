<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('daily_digest')->default(true)->after('must_change_password');
            $table->timestamp('digest_sent_at')->nullable()->after('daily_digest');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['daily_digest', 'digest_sent_at']);
        });
    }
};

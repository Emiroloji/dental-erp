<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Personelin bağlı olduğu şube. "Sadece kendi şubesi" yetki kapsamı bu
     * kolona göre çözülür (kurallar.md Bölüm 4). Mevcut personel, kendi
     * organizasyonunun ilk şubesine atanır.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
        });

        DB::table('users')
            ->where('role', 'staff')
            ->whereNull('branch_id')
            ->whereNotNull('organization_id')
            ->orderBy('id')
            ->each(function (object $user) {
                $branchId = DB::table('branches')
                    ->where('organization_id', $user->organization_id)
                    ->orderBy('id')
                    ->value('id');

                DB::table('users')->where('id', $user->id)->update(['branch_id' => $branchId]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};

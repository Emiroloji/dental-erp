<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('first_name', 50);
            $table->string('last_name', 50);
            $table->string('identity_type', 16);
            $table->text('identity_no')->nullable();
            $table->string('identity_hash', 64)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'identity_hash']);
            $table->index(['organization_id', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};

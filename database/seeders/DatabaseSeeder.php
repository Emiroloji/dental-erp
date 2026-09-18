<?php

namespace Database\Seeders;

use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Organization;
use App\Domain\Organization\Models\Warehouse;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $organization = Organization::create([
            'name' => 'Gülümseme Diş Hastanesi',
            'status' => 'active',
            // Çok şubeli demo hastane: Profesyonel paket (5 şube / 25 kullanıcı / 10 GB).
            'plan' => 'professional',
        ]);

        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Merkez Şube',
            'status' => 'active',
        ]);

        Warehouse::create([
            'branch_id' => $branch->id,
            'name' => 'Varsayılan Depo',
            'is_default' => true,
            'status' => 'active',
        ]);

        User::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Admin',
            'email' => 'admin@dental-erp.test',
            'password' => bcrypt('password'),
            'role' => User::ROLE_ADMIN,
            'status' => 'active',
        ]);
    }
}

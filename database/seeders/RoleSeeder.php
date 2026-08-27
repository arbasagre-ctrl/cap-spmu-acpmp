<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $activeRoles = [
            UserRole::Borrower,
            UserRole::Spmu,
            UserRole::Ictu,
        ];

        foreach ($activeRoles as $role) {
            Role::query()->updateOrCreate(
                ['role_code' => $role->value],
                [
                    'role_name' => $role->label(),
                    'active' => true,
                ],
            );
        }

        // Historical compatibility only. These are no longer active system roles.
        Role::query()
            ->whereIn('role_code', [
                UserRole::Gsu->value,
                UserRole::Vpaf->value,
                'LAUNDRY',
            ])
            ->update(['active' => false]);
    }
}

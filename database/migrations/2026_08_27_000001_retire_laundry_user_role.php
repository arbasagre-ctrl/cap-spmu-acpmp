<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('roles')) {
            $laundryRoleIds = DB::table('roles')
                ->where('role_code', 'LAUNDRY')
                ->pluck('id');

            if (Schema::hasTable('user_roles') && $laundryRoleIds->isNotEmpty()) {
                DB::table('user_roles')
                    ->whereIn('role_id', $laundryRoleIds)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => $now]);
            }

            DB::table('roles')
                ->whereIn('id', $laundryRoleIds)
                ->update([
                    'active' => false,
                    'updated_at' => $now,
                ]);
        }

        if (Schema::hasTable('users')) {
            DB::table('users')
                ->where('access_classification', 'LAUNDRY_WORKER')
                ->update([
                    'access_classification' => 'RETIRED_INACTIVE',
                    'account_status' => 'INACTIVE',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible. Restoring retired portal authority must
        // be an explicit institutional decision, not an automatic rollback.
    }
};

<?php

use Database\Seeders\OrganizationalUnitSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('organizational_units')) {
            return;
        }

        /*
         * This is a data-only migration.  The reusable seeder is deliberately
         * the single definition of the CSPC hierarchy so fresh databases and
         * existing deployments receive identical rows without a second
         * runtime catalogue.  It upserts by stable unit_code, preserving the
         * IDs that users, requests, and audit records already reference.
         */
        app(OrganizationalUnitSeeder::class)->run();
    }

    public function down(): void
    {
        /*
         * Intentionally non-destructive: a rollback must not erase or
         * reparent organizational records that may have been referenced after
         * this hierarchy was applied.
         */
    }
};

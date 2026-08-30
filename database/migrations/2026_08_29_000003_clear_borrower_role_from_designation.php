<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'designation')) {
            return;
        }

        DB::table('users')
            ->where('access_classification', 'BORROWER_ONLY')
            ->whereRaw('LOWER(TRIM(designation)) = ?', ['borrower'])
            ->update(['designation' => null]);
    }

    public function down(): void
    {
        // Intentionally do not restore “Borrower” as a designation. It is a
        // portal access role, not an institutional position/title.
    }
};

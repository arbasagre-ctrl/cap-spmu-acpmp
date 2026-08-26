<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('custody_transactions')) {
            DB::table('custody_transactions')
                ->where('status', 'EARLY_RETURN')
                ->update([
                    'status' => 'ACTIVE',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Historical Early Return data is intentionally not recreated as an active workflow.
    }
};

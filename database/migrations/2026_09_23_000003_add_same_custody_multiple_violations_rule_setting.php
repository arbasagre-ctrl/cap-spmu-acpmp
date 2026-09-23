<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $now = now();

        DB::table('system_settings')->updateOrInsert(
            ['setting_key' => 'same_custody_multiple_violations_rule'],
            [
                'setting_key' => 'same_custody_multiple_violations_rule',
                'value_json' => json_encode(null),
                'data_type' => 'STRING',
                'group_code' => 'PENALTY',
                'description' => 'Open for institutional policy confirmation: SAME_OCCURRENCE or SEPARATE_OCCURRENCES when one custody transaction has both a confirmed Late Return and a confirmed Property Accountability violation. Unset leaves the second confirmed matter Policy Pending (no offense number/sanction created) until confirmed.',
                'status' => 'ACTIVE',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('setting_key', 'same_custody_multiple_violations_rule')->delete();
        }
    }
};

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

        $keys = [
            'billing_statement_template_version',
            'laundry_form_template_version',
            'gate_pass_template_version',
        ];

        $rows = DB::table('system_settings')
            ->whereIn('setting_key', $keys)
            ->get(['id', 'value_json']);

        foreach ($rows as $row) {
            $raw = $row->value_json;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $current = is_string($decoded) ? trim($decoded) : trim((string) $raw, " \t\n\r\0\x0B\"");

            if (strcasecmp($current, 'Standard') !== 0) {
                continue;
            }

            DB::table('system_settings')
                ->where('id', $row->id)
                ->update([
                    'value_json' => json_encode('v1.0'),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Do not revert version references to the ambiguous legacy value
        // "Standard". Version labels remain explicit after rollback.
    }
};

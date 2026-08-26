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
        $rows = [
            ['setting_key' => 'billing_statement_template_version', 'value_json' => json_encode('Standard'), 'data_type' => 'STRING', 'group_code' => 'DOCUMENT', 'description' => 'Controlled Billing Statement template/version reference.'],
            ['setting_key' => 'laundry_form_template_version', 'value_json' => json_encode('Standard'), 'data_type' => 'STRING', 'group_code' => 'DOCUMENT', 'description' => 'Controlled Laundry Form template/version reference.'],
            ['setting_key' => 'gate_pass_template_version', 'value_json' => json_encode('Standard'), 'data_type' => 'STRING', 'group_code' => 'DOCUMENT', 'description' => 'Controlled Gate Pass template/version reference.'],
        ];

        foreach ($rows as $row) {
            DB::table('system_settings')->updateOrInsert(
                ['setting_key' => $row['setting_key']],
                $row + ['status' => 'ACTIVE', 'created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->whereIn('setting_key', [
                'billing_statement_template_version',
                'laundry_form_template_version',
                'gate_pass_template_version',
            ])->delete();
        }
    }
};

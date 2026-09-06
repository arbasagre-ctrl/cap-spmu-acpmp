<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_templates')) {
            foreach ([
                'BORROWER_SLIP' => "Borrower's Slip Template",
                'RSLDDP' => 'RSLDDP Template',
            ] as $type => $name) {
                if (! DB::table('document_templates')->where('document_type', $type)->exists()) {
                    DB::table('document_templates')->insert([
                        'document_type' => $type,
                        'template_version' => 1,
                        'version_label' => 'v1.0',
                        'template_name' => $name.' v1.0',
                        'content_template' => null,
                        'stored_file_id' => null,
                        'source_mode' => 'BUILT_IN',
                        'change_reason' => 'Initial built-in system template.',
                        'status' => 'ACTIVE',
                        'configured_by_user_id' => null,
                        'activated_at' => now(),
                        'superseded_at' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (Schema::hasTable('system_settings')) {
            foreach ([
                'borrower_slip_template_version' => [
                    'value' => 'v1.0',
                    'description' => "Active controlled Borrower's Slip template version.",
                ],
                'rslddp_template_version' => [
                    'value' => 'v1.0',
                    'description' => 'Active controlled RSLDDP template version.',
                ],
            ] as $key => $meta) {
                DB::table('system_settings')->updateOrInsert(
                    ['setting_key' => $key],
                    [
                        'value_json' => json_encode($meta['value']),
                        'data_type' => 'STRING',
                        'group_code' => 'DOCUMENT',
                        'description' => $meta['description'],
                        'status' => 'ACTIVE',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->whereIn('setting_key', [
                    'borrower_slip_template_version',
                    'rslddp_template_version',
                ])
                ->delete();
        }

        if (Schema::hasTable('document_templates')) {
            DB::table('document_templates')
                ->whereIn('document_type', ['BORROWER_SLIP', 'RSLDDP'])
                ->where('template_version', 1)
                ->whereNull('stored_file_id')
                ->delete();
        }
    }
};

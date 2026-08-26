<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_templates')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                if (! Schema::hasColumn('document_templates', 'version_label')) {
                    $table->string('version_label', 30)->nullable()->after('template_version');
                }
                if (! Schema::hasColumn('document_templates', 'stored_file_id')) {
                    $table->foreignId('stored_file_id')->nullable()->after('content_template')
                        ->constrained('stored_files')->nullOnDelete();
                }
                if (! Schema::hasColumn('document_templates', 'source_mode')) {
                    $table->string('source_mode', 30)->default('BUILT_IN')->after('stored_file_id');
                }
                if (! Schema::hasColumn('document_templates', 'change_reason')) {
                    $table->text('change_reason')->nullable()->after('source_mode');
                }
                if (! Schema::hasColumn('document_templates', 'activated_at')) {
                    $table->timestamp('activated_at')->nullable()->after('configured_by_user_id');
                }
                if (! Schema::hasColumn('document_templates', 'superseded_at')) {
                    $table->timestamp('superseded_at')->nullable()->after('activated_at');
                }
            });

            foreach ([
                'BILLING_STATEMENT' => 'Billing Statement Template',
                'GATE_PASS' => 'Gate Pass Template',
                'LAUNDRY_FORM' => 'Laundry Form Template',
            ] as $type => $name) {
                $exists = DB::table('document_templates')
                    ->where('document_type', $type)
                    ->exists();

                if (! $exists) {
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
                } else {
                    DB::table('document_templates')
                        ->where('document_type', $type)
                        ->whereNull('version_label')
                        ->update([
                            'version_label' => DB::raw("CONCAT('v', template_version, '.0')"),
                            'updated_at' => now(),
                        ]);
                }
            }
        }

        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('setting_key', 'overdue_grace_hours')
                ->update([
                    'value_json' => json_encode(0),
                    'status' => 'RETIRED',
                    'description' => 'Legacy compatibility only. There is no grace period: a borrowing becomes overdue on the calendar day after the Expected Return Date when issued quantity remains outstanding.',
                    'updated_at' => now(),
                ]);

            DB::table('system_settings')
                ->where('setting_key', 'due_soon_hours')
                ->update([
                    'status' => 'RETIRED',
                    'description' => 'Legacy compatibility only. Return reminders are date-based: one day before the Expected Return Date and again on the due date.',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')
                ->where('setting_key', 'overdue_grace_hours')
                ->update(['status' => 'ACTIVE', 'updated_at' => now()]);
            DB::table('system_settings')
                ->where('setting_key', 'due_soon_hours')
                ->update(['status' => 'ACTIVE', 'updated_at' => now()]);
        }

        if (Schema::hasTable('document_templates')) {
            Schema::table('document_templates', function (Blueprint $table): void {
                if (Schema::hasColumn('document_templates', 'stored_file_id')) {
                    $table->dropConstrainedForeignId('stored_file_id');
                }
                foreach (['version_label', 'source_mode', 'change_reason', 'activated_at', 'superseded_at'] as $column) {
                    if (Schema::hasColumn('document_templates', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};

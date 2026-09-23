<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->settings() as $key => [$value, $type, $group, $description]) {
            SystemSetting::query()->firstOrCreate(['setting_key' => $key], [
                'value_json' => $value,
                'data_type' => $type,
                'group_code' => $group,
                'description' => $description,
                'status' => 'ACTIVE',
            ]);
        }
    }

    private function settings(): array
    {
        return [
            'approved_letter_download_time' => ['23:59', 'TIME', 'WORKFLOW', 'Same-day approved-letter deadline in Asia/Manila.'],
            'overdue_grace_hours' => [0, 'INTEGER', 'LEGACY', 'Legacy compatibility only. No grace period is applied; overdue begins on the next calendar day when issued property remains outstanding.'],
            'daily_overdue_tariff' => [null, 'MONEY', 'PENALTY', 'Open for SPMU policy finalization.'],
            'sms_provider' => [null, 'STRING', 'NOTIFICATION', 'Open for ICTU provider configuration.'],
            'due_soon_hours' => [24, 'INTEGER', 'LEGACY', 'Legacy compatibility only. Current reminders are date-based: one day before and on the Expected Return Date.'],
            'rslddp_template_status' => ['PROVISIONAL', 'STRING', 'DOCUMENT', 'Official layout and appraisal requirements remain open.'],
            'same_custody_multiple_violations_rule' => [null, 'STRING', 'PENALTY', 'Open for institutional policy confirmation: SAME_OCCURRENCE or SEPARATE_OCCURRENCES when one custody transaction has both a confirmed Late Return and a confirmed Property Accountability violation. Unset leaves the second confirmed matter Policy Pending (no offense number/sanction created) until confirmed.'],
            'max_upload_mb' => [5, 'INTEGER', 'SECURITY', 'Editable evidence and signature upload limit.'],
            'backup_schedule' => ['NOT_FINALIZED', 'STRING', 'ICTU', 'ICTU must finalize before production deployment.'],
        ];
    }
}

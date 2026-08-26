<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorizeConfiguration($request);

        $hiddenSettingKeys = [
            'overdue_grace_hours',
            'due_soon_hours',
            'billing_statement_template_version',
            'laundry_form_template_version',
            'gate_pass_template_version',
        ];

        $settings = SystemSetting::query()
            ->whereNotIn('setting_key', $hiddenSettingKeys)
            ->orderBy('group_code')
            ->orderBy('setting_key')
            ->get();

        $templateTypes = [
            'BILLING_STATEMENT' => 'Billing Statement Template',
            'GATE_PASS' => 'Gate Pass Template',
            'LAUNDRY_FORM' => 'Laundry Form Template',
        ];

        $documentTemplates = DocumentTemplate::query()
            ->with('file')
            ->whereIn('document_type', array_keys($templateTypes))
            ->orderByDesc('template_version')
            ->get()
            ->groupBy('document_type');

        return view('administration.settings', compact(
            'settings',
            'templateTypes',
            'documentTemplates'
        ));
    }

    public function update(Request $request, SystemSetting $setting, AuditService $audit): RedirectResponse
    {
        $this->authorizeConfiguration($request);

        $data = $request->validate(['value' => ['nullable', 'string', 'max:2000'], 'reason' => ['required', 'string', 'max:1000']]);
        $before = $setting->value_json;
        $after = match ($setting->data_type) {
            'INTEGER' => filled($data['value']) ? (int) $data['value'] : null,
            'MONEY' => filled($data['value']) ? round((float) $data['value'], 2) : null,
            default => filled($data['value']) ? $data['value'] : null,
        };
        DB::transaction(function () use ($setting, $request, $before, $after, $data, $audit): void {
            $setting->update(['value_json' => $after, 'updated_by_user_id' => $request->user()->id]);
            DB::table('configuration_changes')->insert([
                'system_setting_id' => $setting->id,
                'changed_by_user_id' => $request->user()->id,
                'before_value_json' => json_encode($before),
                'after_value_json' => json_encode($after),
                'reason' => $data['reason'],
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $audit->record('SYSTEM_SETTING_CHANGED', $setting, reason: $data['reason'], before: ['value' => $before], after: ['value' => $after]);
        });

        return back()->with('status', 'Configuration updated prospectively with before/after history.');
    }

    private function authorizeConfiguration(Request $request): void
    {
        abort_unless(
            in_array(
                $request->user()?->access_classification,
                [AccessClassification::SpmuHead, AccessClassification::IctuMaintainer],
                true
            ),
            403,
            'Only the SPMU Head or ICTU Maintainer may change operational configuration.'
        );
    }
}

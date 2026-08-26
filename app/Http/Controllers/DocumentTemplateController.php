<?php

namespace App\Http\Controllers;

use App\Enums\AccessClassification;
use App\Models\DocumentTemplate;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\ProtectedFileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentTemplateController extends Controller
{
    private const TYPES = [
        'BILLING_STATEMENT' => [
            'name' => 'Billing Statement Template',
            'setting_key' => 'billing_statement_template_version',
        ],
        'GATE_PASS' => [
            'name' => 'Gate Pass Template',
            'setting_key' => 'gate_pass_template_version',
        ],
        'LAUNDRY_FORM' => [
            'name' => 'Laundry Form Template',
            'setting_key' => 'laundry_form_template_version',
        ],
    ];

    public function store(
        Request $request,
        string $type,
        ProtectedFileService $files,
        AuditService $audit
    ): RedirectResponse {
        $this->authorizeConfiguration($request);

        $type = strtoupper(str_replace('-', '_', trim($type)));
        abort_unless(isset(self::TYPES[$type]), 404);

        $data = $request->validate([
            'version_label' => ['required', 'string', 'max:30', 'regex:/^v?\d+(?:\.\d+){0,2}$/i'],
            'template_file' => ['required', 'file', 'max:10240', 'mimes:html,htm,pdf,doc,docx'],
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'version_label.regex' => 'Use a version such as v1.1, v2.0, or 2.1.',
            'template_file.mimes' => 'Upload an HTML, PDF, DOC, or DOCX template file.',
        ]);

        $versionLabel = strtolower($data['version_label']);
        if (! str_starts_with($versionLabel, 'v')) {
            $versionLabel = 'v'.$versionLabel;
        }

        if (DocumentTemplate::query()
            ->where('document_type', $type)
            ->whereRaw('LOWER(version_label) = ?', [strtolower($versionLabel)])
            ->exists()) {
            throw ValidationException::withMessages([
                'version_label' => 'That version already exists for this document type. Use a new version label.',
            ]);
        }

        $upload = $request->file('template_file');
        $extension = strtolower($upload->getClientOriginalExtension());
        $isHtml = in_array($extension, ['html', 'htm'], true)
            || str_contains((string) $upload->getMimeType(), 'html');

        if ($isHtml) {
            $html = (string) file_get_contents($upload->getRealPath());
            if (! str_contains($html, '{{generated_content}}')) {
                throw ValidationException::withMessages([
                    'template_file' => 'An auto-fill HTML template must contain the {{generated_content}} placeholder.',
                ]);
            }
        }

        $storedFile = $files->storeUpload(
            $upload,
            'document-templates/'.strtolower($type),
            'DOCUMENT_TEMPLATE_SOURCE'
        );

        $template = DB::transaction(function () use (
            $request,
            $type,
            $versionLabel,
            $data,
            $storedFile,
            $isHtml,
            $audit
        ): DocumentTemplate {
            $activeTemplates = DocumentTemplate::query()
                ->where('document_type', $type)
                ->where('status', 'ACTIVE')
                ->lockForUpdate()
                ->get();

            $before = $activeTemplates->map(fn (DocumentTemplate $template) => [
                'id' => $template->id,
                'version' => $template->version_label ?: 'v'.$template->template_version.'.0',
                'file' => $template->file?->original_name,
            ])->values()->all();

            DocumentTemplate::query()
                ->where('document_type', $type)
                ->where('status', 'ACTIVE')
                ->update([
                    'status' => 'HISTORICAL',
                    'superseded_at' => now(),
                    'updated_at' => now(),
                ]);

            $nextVersion = ((int) DocumentTemplate::query()
                ->where('document_type', $type)
                ->max('template_version')) + 1;

            $template = DocumentTemplate::query()->create([
                'document_type' => $type,
                'template_version' => max(1, $nextVersion),
                'version_label' => $versionLabel,
                'template_name' => self::TYPES[$type]['name'].' '.$versionLabel,
                'stored_file_id' => $storedFile->id,
                'source_mode' => $isHtml ? 'HTML_PLACEHOLDER' : 'REFERENCE_ONLY',
                'change_reason' => $data['reason'],
                'status' => 'ACTIVE',
                'configured_by_user_id' => $request->user()->id,
                'activated_at' => now(),
            ]);

            $setting = SystemSetting::query()
                ->where('setting_key', self::TYPES[$type]['setting_key'])
                ->first();

            if ($setting) {
                $setting->update([
                    'value_json' => $versionLabel,
                    'updated_by_user_id' => $request->user()->id,
                ]);
            }

            $audit->record(
                'DOCUMENT_TEMPLATE_ACTIVATED',
                $template,
                reason: $data['reason'],
                before: ['active_templates' => $before],
                after: [
                    'document_type' => $type,
                    'version_label' => $versionLabel,
                    'source_mode' => $template->source_mode,
                    'source_file' => $storedFile->original_name,
                ]
            );

            return $template;
        }, 3);

        $message = $template->source_mode === 'HTML_PLACEHOLDER'
            ? "{$template->version_label} is active. New generated documents will use this uploaded HTML template shell."
            : "{$template->version_label} is active as a controlled reference file. New generated documents are linked to this version, while the built-in auto-fill layout remains in use because PDF/DOCX files cannot be safely auto-filled without mapped fields.";

        return back()->with('status', $message);
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
            'Only the SPMU Head or ICTU Maintainer may change document templates.'
        );
    }
}

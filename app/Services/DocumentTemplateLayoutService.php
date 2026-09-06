<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Read-only analysis of an approved document source.
 *
 * An official layout is either deterministically usable by the production
 * renderer or it is rejected for automatic preparation. This service
 * deliberately never turns an uncertain label into an administrator task.
 */
class DocumentTemplateLayoutService
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** @var array<string, array<string, array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}>> */
    private const FIELD_DEFINITIONS = [
        'BORROWER_SLIP' => [
            // The official form's top data is filled only from the existing
            // request/custody profile. Its handwritten signature cells remain
            // intentionally outside this schema.
            'document_date' => ['label' => 'Document Date', 'aliases' => ['date'], 'required' => false, 'manual' => false, 'table' => null],
            'employee_checkbox' => ['label' => 'Employee', 'aliases' => ['employee'], 'required' => false, 'manual' => false, 'table' => null],
            'others_checkbox' => ['label' => 'Others', 'aliases' => ['others'], 'required' => false, 'manual' => false, 'table' => null],
            'other_classification' => ['label' => 'Others Classification', 'aliases' => ['others classification'], 'required' => false, 'manual' => false, 'table' => null],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['qty', 'quantity'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'items.unit' => ['label' => 'Unit', 'aliases' => ['unit'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'items.description' => ['label' => 'Article / Description', 'aliases' => ['article / description', 'article description', 'description'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'purpose' => ['label' => 'Purpose', 'aliases' => ['purpose', 'will be used for', 'used for'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'expected_return_date' => ['label' => 'Expected Return Date', 'aliases' => ['expected date of return', 'expected return date', 'date of return', 'returned on'], 'required' => true, 'manual' => false, 'table' => 'borrowed_items'],
            'date_released' => ['label' => 'Date Released', 'aliases' => ['date released', 'release date'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'release_time' => ['label' => 'Release Time', 'aliases' => ['release time', 'time released'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'date_returned' => ['label' => 'Date Returned', 'aliases' => ['date returned', 'return date'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'remarks' => ['label' => 'Remarks', 'aliases' => ['remarks', 'return remarks'], 'required' => false, 'manual' => false, 'table' => 'release_return'],
            'borrowed_by_printed_name' => ['label' => 'Borrowed By Printed Name', 'aliases' => ['borrowed by'], 'required' => false, 'manual' => false, 'table' => null],
            'borrowed_by_designation' => ['label' => 'Borrowed By Designation', 'aliases' => ['borrowed by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'borrowed_by_date' => ['label' => 'Borrowed By Date', 'aliases' => ['borrowed by date'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_printed_name' => ['label' => 'Approved By Printed Name', 'aliases' => ['approved by'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_designation' => ['label' => 'Approved By Designation', 'aliases' => ['approved by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by_date' => ['label' => 'Approved By Date', 'aliases' => ['approved by date'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by_printed_name' => ['label' => 'Issued By Printed Name', 'aliases' => ['issued by'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by_designation' => ['label' => 'Issued By Designation', 'aliases' => ['issued by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by_date' => ['label' => 'Issued By Date', 'aliases' => ['issued by date'], 'required' => false, 'manual' => false, 'table' => null],
            'return_received_by_printed_name' => ['label' => 'Return Received By Printed Name', 'aliases' => ['received by'], 'required' => false, 'manual' => false, 'table' => null],
            'return_received_by_designation' => ['label' => 'Return Received By Designation', 'aliases' => ['return received by designation'], 'required' => false, 'manual' => false, 'table' => null],
            'return_received_by_date' => ['label' => 'Return Received By Date', 'aliases' => ['return received by date'], 'required' => false, 'manual' => false, 'table' => null],
            'borrowed_by' => ['label' => 'Borrowed By', 'aliases' => ['borrowed by', 'borrower signature'], 'required' => false, 'manual' => true, 'table' => null],
            'approved_by' => ['label' => 'Approved By', 'aliases' => ['approved by', 'approved'], 'required' => false, 'manual' => false, 'table' => null],
            'issued_by' => ['label' => 'Issued By', 'aliases' => ['issued by'], 'required' => false, 'manual' => true, 'table' => null],
            'received_by' => ['label' => 'Received By', 'aliases' => ['received by'], 'required' => false, 'manual' => true, 'table' => null],
            'returned_by' => ['label' => 'Returned By', 'aliases' => ['returned by'], 'required' => false, 'manual' => true, 'table' => null],
            'return_received_by' => ['label' => 'Return Received By', 'aliases' => ['return received by'], 'required' => false, 'manual' => true, 'table' => null],
            'verified_by' => ['label' => 'Verified By', 'aliases' => ['verified by'], 'required' => false, 'manual' => true, 'table' => null],
        ],
        'LAUNDRY_FORM' => [
            'request_no' => ['label' => 'Request Number', 'aliases' => ['request no', 'request number'], 'required' => false, 'manual' => false, 'table' => null],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'requested by'], 'required' => false, 'manual' => false, 'table' => null],
            'requesting_office' => ['label' => 'Requesting Office', 'aliases' => ['requesting office'], 'required' => false, 'manual' => false, 'table' => null],
            'date_requested' => ['label' => 'Date Requested', 'aliases' => ['date requested'], 'required' => false, 'manual' => false, 'table' => null],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['qty', 'quantity'], 'required' => true, 'manual' => false, 'table' => 'laundry_items'],
            'items.unit' => ['label' => 'Unit', 'aliases' => ['unit'], 'required' => true, 'manual' => false, 'table' => 'laundry_items'],
            'items.description' => ['label' => 'Description', 'aliases' => ['description', 'article'], 'required' => true, 'manual' => false, 'table' => 'laundry_items'],
            'date_completed' => ['label' => 'Date Completed', 'aliases' => ['date completed'], 'required' => false, 'manual' => false, 'table' => null],
            'received_by' => ['label' => 'Received By', 'aliases' => ['received by'], 'required' => false, 'manual' => true, 'table' => null],
        ],
        'GATE_PASS' => [
            'gate_pass_no' => ['label' => 'Gate Pass Number', 'aliases' => ['gp no', 'gate pass no', 'gate pass number'], 'required' => false, 'manual' => false, 'table' => null],
            'borrower_name' => ['label' => 'Bearer', 'aliases' => ['bearer', 'accountable person'], 'required' => false, 'manual' => false, 'table' => null],
            'purpose' => ['label' => 'Purpose', 'aliases' => ['purpose'], 'required' => true, 'manual' => false, 'table' => null],
            'destination' => ['label' => 'Destination', 'aliases' => ['destination'], 'required' => false, 'manual' => false, 'table' => null],
            'items.qty' => ['label' => 'Quantity', 'aliases' => ['quantity', 'qty'], 'required' => true, 'manual' => false, 'table' => 'gate_items'],
            'items.unit' => ['label' => 'Unit', 'aliases' => ['unit'], 'required' => true, 'manual' => false, 'table' => 'gate_items'],
            'items.description' => ['label' => 'Description', 'aliases' => ['description', 'article'], 'required' => true, 'manual' => false, 'table' => 'gate_items'],
            'issued_by' => ['label' => 'Released By', 'aliases' => ['released by', 'issued by'], 'required' => false, 'manual' => true, 'table' => null],
            'verified_by' => ['label' => 'Verified By', 'aliases' => ['verified by'], 'required' => false, 'manual' => false, 'table' => null],
            'approved_by' => ['label' => 'Approved By', 'aliases' => ['approved by'], 'required' => false, 'manual' => false, 'table' => null],
        ],
        'BILLING_STATEMENT' => [
            'billing_no' => ['label' => 'Billing Statement Number', 'aliases' => ['billing no', 'billing statement no', 'billing statement number'], 'required' => true, 'manual' => false, 'table' => null],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'billed to'], 'required' => true, 'manual' => false, 'table' => null],
            'issued_date' => ['label' => 'Issued Date', 'aliases' => ['issued date', 'date issued'], 'required' => false, 'manual' => false, 'table' => null],
            'due_date' => ['label' => 'Due Date', 'aliases' => ['due date', 'payment due date'], 'required' => false, 'manual' => false, 'table' => null],
            'items.description' => ['label' => 'Description', 'aliases' => ['description', 'particulars'], 'required' => true, 'manual' => false, 'table' => 'billing_lines'],
            'items.amount' => ['label' => 'Amount', 'aliases' => ['amount'], 'required' => true, 'manual' => false, 'table' => 'billing_lines'],
            'total_amount' => ['label' => 'Total Amount', 'aliases' => ['total amount', 'total'], 'required' => true, 'manual' => false, 'table' => null],
            'issued_by' => ['label' => 'Issued By', 'aliases' => ['issued by'], 'required' => false, 'manual' => true, 'table' => null],
        ],
        'RSLDDP' => [
            'incident_no' => ['label' => 'Incident Number', 'aliases' => ['incident no', 'incident number'], 'required' => true, 'manual' => false, 'table' => null],
            'borrower_name' => ['label' => 'Borrower', 'aliases' => ['borrower', 'accountable person'], 'required' => true, 'manual' => false, 'table' => null],
            'incident_type' => ['label' => 'Incident Type', 'aliases' => ['incident type'], 'required' => false, 'manual' => false, 'table' => null],
            'reported_date' => ['label' => 'Reported Date', 'aliases' => ['reported date', 'date reported'], 'required' => false, 'manual' => false, 'table' => null],
            'items.qty' => ['label' => 'Affected Quantity', 'aliases' => ['quantity', 'qty'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.description' => ['label' => 'Affected Property', 'aliases' => ['affected property', 'property description', 'description'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'items.condition' => ['label' => 'Observed Condition', 'aliases' => ['observed condition', 'condition'], 'required' => true, 'manual' => false, 'table' => 'affected_items'],
            'reported_by' => ['label' => 'Reported By', 'aliases' => ['reported by'], 'required' => false, 'manual' => true, 'table' => null],
            'noted_by' => ['label' => 'Noted By', 'aliases' => ['noted by'], 'required' => false, 'manual' => true, 'table' => null],
        ],
    ];

    /** @return array<string,string> */
    public function fields(string $type): array
    {
        return collect(self::FIELD_DEFINITIONS[$type] ?? [])->map(fn (array $field): string => $field['label'])->all();
    }

    /** @return array<string,array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}> */
    public function fieldDefinitions(string $type): array
    {
        return self::FIELD_DEFINITIONS[$type] ?? [];
    }

    /** @return list<string> */
    public function requiredFields(string $type): array
    {
        return array_keys(array_filter(self::FIELD_DEFINITIONS[$type] ?? [], fn (array $field): bool => $field['required']));
    }

    /** @return array{format:string,review:array<string,mixed>} */
    public function inspectUpload(UploadedFile $upload): array
    {
        if (($upload->getSize() ?? 0) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['template_file' => 'The approved layout must be 10 MB or smaller.']);
        }
        $bytes = (string) file_get_contents($upload->getRealPath());
        return $this->inspectSource(
            $bytes,
            $upload->getClientOriginalName(),
            $upload->getMimeType(),
            $upload->getClientOriginalExtension(),
        );
    }

    /**
     * Inspect the PDF that production will import. Office layouts can be
     * system-ready when their conversion preserves named form controls.
     *
     * @return array<string,mixed>
     */
    public function inspectProductionPdf(string $bytes, bool $withLayoutAnalysis = false): array
    {
        return $this->inspectPdf($bytes, $withLayoutAnalysis);
    }

    /**
     * Re-identify a preserved source before preparation. The stored metadata
     * is informative only: a valid PDF header must remain a PDF even if an
     * earlier draft did not persist its format value correctly.
     *
     * @return array{format:string,review:array<string,mixed>}
     */
    public function inspectStoredSource(string $bytes, ?string $originalName = null, ?string $mimeType = null, ?string $declaredFormat = null, bool $withLayoutAnalysis = false): array
    {
        return $this->inspectSource($bytes, $originalName, $mimeType, $declaredFormat, $withLayoutAnalysis);
    }

    /** @return array{format:string,review:array<string,mixed>} */
    private function inspectSource(string $bytes, ?string $originalName, ?string $mimeType, ?string $declaredFormat, bool $withLayoutAnalysis = false): array
    {
        $format = $this->detectSourceFormat($bytes, $originalName, $mimeType, $declaredFormat);

        return match ($format) {
            'PDF' => ['format' => 'PDF', 'review' => $this->inspectPdf($bytes, $withLayoutAnalysis)],
            'DOCX' => ['format' => 'DOCX', 'review' => $this->inspectDocx($bytes)],
            'XLSX' => ['format' => 'XLSX', 'review' => $this->inspectXlsx($bytes)],
            default => throw ValidationException::withMessages(['template' => 'The uploaded layout has no supported production format.']),
        };
    }

    private function detectSourceFormat(string $bytes, ?string $originalName, ?string $mimeType, ?string $declaredFormat): ?string
    {
        $mimeType = strtolower(trim((string) $mimeType));
        $extension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
        $declaredFormat = strtoupper(trim((string) $declaredFormat));
        $hasPdfHeader = str_starts_with(ltrim($bytes), '%PDF-');

        // Content is authoritative for PDFs. MIME and filename are retained
        // as corroborating signals and to give malformed PDF uploads a clear
        // PDF validation error instead of misclassifying them as unsupported.
        if ($hasPdfHeader || str_contains($mimeType, 'pdf') || $extension === 'pdf' || $declaredFormat === 'PDF') {
            return 'PDF';
        }

        $isDocx = str_contains($mimeType, 'wordprocessingml.document') || $extension === 'docx' || $declaredFormat === 'DOCX';
        if ($isDocx) {
            return 'DOCX';
        }

        $isXlsx = str_contains($mimeType, 'spreadsheetml.sheet') || $extension === 'xlsx' || $declaredFormat === 'XLSX';
        if ($isXlsx) {
            return 'XLSX';
        }

        return null;
    }

    /** @return array{format:string,render_representation:string,review:array<string,mixed>,mappings:list<array<string,mixed>>,table_layouts:array<string,array{row_height_percent:float,max_rows:int}>,analysis:array<string,mixed>} */
    public function configuration(DocumentTemplate $template): array
    {
        $decoded = json_decode((string) $template->content_template, true);
        $mappings = is_array($decoded['mappings'] ?? null) ? array_values($decoded['mappings']) : [];
        $tableLayouts = $this->normalizeTableLayouts((string) $template->document_type, is_array($decoded['table_layouts'] ?? null) ? $decoded['table_layouts'] : []);
        $analysis = is_array($decoded['analysis'] ?? null)
            ? $decoded['analysis']
            : $this->analysis((string) $template->document_type, $mappings, [], $tableLayouts);
        return [
            'format' => (string) ($decoded['format'] ?? ''),
            'render_representation' => (string) ($decoded['render_representation'] ?? ''),
            'review' => is_array($decoded['review'] ?? null) ? $decoded['review'] : [],
            'mappings' => $mappings,
            'table_layouts' => $tableLayouts,
            'analysis' => $analysis,
        ];
    }

    /** @return array{mappings:list<array<string,mixed>>,table_layouts:array<string,array{row_height_percent:float,max_rows:int}>,analysis:array<string,mixed>} */
    public function autoMap(string $type, string $format, array $review): array
    {
        $formMappings = is_array($review['fillable_widgets'] ?? null)
            ? $this->automaticPdfFormFieldMappings($type, $review)
            : ['mappings' => [], 'table_layouts' => []];
        $layoutMappings = $format === 'PDF' || ! empty($review['layout_words'])
            ? $this->automaticPdfLayoutMappings($type, $review, $formMappings['mappings'])
            : ['mappings' => [], 'table_layouts' => []];
        $borrowerSlipMappings = $type === 'BORROWER_SLIP'
            ? $this->automaticBorrowerSlipSystemMappings($review, $layoutMappings['mappings'])
            : ['mappings' => [], 'table_layouts' => []];
        $mappings = collect([...$layoutMappings['mappings'], ...$borrowerSlipMappings['mappings'], ...$formMappings['mappings']])
            ->keyBy('field')
            ->values()
            ->all();
        $tableLayouts = array_replace(
            $layoutMappings['table_layouts'],
            $borrowerSlipMappings['table_layouts'],
            $formMappings['table_layouts'],
        );
        $mappedFields = collect($mappings)->pluck('field')->filter()->all();
        $suggestions = [];
        foreach ($this->fieldDefinitions($type) as $key => $definition) {
            if ($definition['manual'] || in_array($key, $mappedFields, true)) {
                continue;
            }
            $candidate = $this->detectField($key, $definition, $format, $review);
            if ($candidate !== null) {
                $suggestions[$key] = $candidate;
            }
        }

        return [
            'mappings' => $mappings,
            'table_layouts' => $tableLayouts,
            'analysis' => $this->analysis($type, $mappings, $suggestions, $tableLayouts),
        ];
    }

    /** @param array<int,array<string,mixed>> $mappings @param array<string,mixed> $suggestions @param array<string,array{row_height_percent:float,max_rows:int}> $tableLayouts @return array<string,mixed> */
    public function analysis(string $type, array $mappings, array $suggestions = [], array $tableLayouts = []): array
    {
        $definitions = $this->fieldDefinitions($type);
        $mapped = collect($mappings)->pluck('field')->filter()->unique()->values()->all();
        $required = $this->requiredFields($type);
        $missing = array_values(array_filter($required, fn (string $key): bool => ! in_array($key, $mapped, true)));
        $mappedByField = collect($mappings)->filter(fn ($mapping) => is_array($mapping) && filled($mapping['field'] ?? null))->keyBy('field');
        $renderMissing = array_values(array_filter($required, function (string $field) use ($mappedByField): bool {
            $mapping = $mappedByField->get($field);

            return ! is_array($mapping)
                || ! str_starts_with((string) ($mapping['target'] ?? ''), 'click:')
                || ! is_numeric($mapping['page'] ?? null)
                || ! is_numeric($mapping['x'] ?? null)
                || ! is_numeric($mapping['y'] ?? null);
        }));
        $tables = [];
        $tableRequirements = [];
        foreach (collect($definitions)->pluck('table')->filter()->unique() as $table) {
            $tableFields = array_keys(array_filter($definitions, fn (array $field): bool => $field['table'] === $table && $field['required']));
            $tables[$table] = [
                'label' => match ($table) {
                    'borrowed_items' => 'Borrowed Items Table',
                    'release_return' => 'Release / Return Table',
                    'laundry_items' => 'Laundry Items Table',
                    'gate_items' => 'Gate Pass Items Table',
                    'billing_lines' => 'Billing Lines Table',
                    default => 'Affected Property Table',
                },
                'detected' => $tableFields !== [] && count(array_diff($tableFields, $mapped)) === 0,
            ];

            $repeatingFields = array_keys(array_filter($definitions, fn (array $field, string $key): bool => $field['table'] === $table && str_starts_with($key, 'items.'), ARRAY_FILTER_USE_BOTH));
            if ($repeatingFields !== []) {
                $layout = $tableLayouts[$table] ?? null;
                $tableRequirements[$table] = [
                    'label' => $tables[$table]['label'],
                    'configured' => is_array($layout)
                        && ($layout['row_height_percent'] ?? 0) > 0
                        && ($layout['max_rows'] ?? 0) > 0,
                ];
            }
        }
        $tableMissing = array_keys(array_filter($tableRequirements, fn (array $table): bool => ! $table['configured']));

        return [
            'detected_count' => count(array_intersect($required, $mapped)),
            'required_count' => count($required),
            'missing' => $missing,
            'render_missing' => $renderMissing,
            'suggestions' => $suggestions,
            'tables' => $tables,
            'table_requirements' => $tableRequirements,
            'table_missing' => $tableMissing,
            'ready' => $missing === [] && $renderMissing === [] && $tableMissing === [],
        ];
    }

    /** @param array<string,mixed> $review @param array<string,mixed> $analysis */
    public function preparationFeedback(string $format, array $review, array $analysis): string
    {
        if ($format === 'PDF' && ! empty($review['scanned']) && empty($review['fillable_widgets'])) {
            return 'The layout contains no readable structure the system can use to prepare document data.';
        }
        if (($analysis['suggestions'] ?? []) !== []) {
            return 'The layout has readable labels, but a required section could not be identified automatically.';
        }

        return 'The system could not identify every required section needed to generate this document.';
    }

    /** @param array<string,mixed> $tableLayouts @return array<string,array{row_height_percent:float,max_rows:int}> */
    public function normalizeTableLayouts(string $type, array $tableLayouts): array
    {
        $allowedTables = array_values(array_unique(array_filter(array_map(
            fn (array $definition): ?string => $definition['table'] ?? null,
            $this->fieldDefinitions($type),
        ))));
        $normalized = [];

        foreach ($allowedTables as $table) {
            $layout = $tableLayouts[$table] ?? null;
            if (! is_array($layout)) {
                continue;
            }
            $rowHeight = is_numeric($layout['row_height_percent'] ?? null) ? (float) $layout['row_height_percent'] : 0.0;
            $maxRows = filter_var($layout['max_rows'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            if ($rowHeight <= 0 || $rowHeight > 25 || $maxRows < 1 || $maxRows > 100) {
                continue;
            }
            $maximumHeight = is_numeric($layout['max_height_percent'] ?? null)
                ? (float) $layout['max_height_percent']
                : $rowHeight * $maxRows;
            if ($maximumHeight < $rowHeight || $maximumHeight > 95) {
                continue;
            }
            $normalized[$table] = [
                'row_height_percent' => round($rowHeight, 2),
                'max_rows' => $maxRows,
                'max_height_percent' => round($maximumHeight, 2),
            ];
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    public function sampleValues(string $type): array
    {
        $common = [
            'borrower_name' => 'Sample Borrower',
            'document_date' => '10 September 2026',
            'employee_checkbox' => 'X',
            'others_checkbox' => '',
            'other_classification' => '',
            'purpose' => 'Seminar',
            'issued_date' => '10 September 2026',
            'expected_return_date' => '12 September 2026',
            'date_released' => '10 September 2026',
            'release_time' => '2:15 PM',
            'date_returned' => '12 September 2026',
            'remarks' => 'Complete',
            'borrowed_by_printed_name' => 'JUAN DELA CRUZ',
            'borrowed_by_designation' => 'Instructor',
            'borrowed_by_date' => '10 September 2026',
            'approved_by_printed_name' => 'SAMPLE SPMU HEAD',
            'approved_by_designation' => 'SPMU Head',
            'approved_by_date' => '9 September 2026',
            'issued_by_printed_name' => 'SAMPLE ACTION OFFICER',
            'issued_by_designation' => 'SPMU Action Officer',
            'issued_by_date' => '10 September 2026',
            'return_received_by_printed_name' => 'SAMPLE RECEIVING OFFICER',
            'return_received_by_designation' => 'SPMU Action Officer',
            'return_received_by_date' => '12 September 2026',
            'items.qty' => '15',
            'items.unit' => 'Piece',
            'items.description' => 'Monoblock Chair',
            'approved_by' => 'SPMU Head approval record',
        ];
        return match ($type) {
            'LAUNDRY_FORM' => $common + ['request_no' => 'BR-2026-0001', 'requesting_office' => 'Sample Office', 'date_requested' => '10 September 2026', 'date_completed' => '12 September 2026'],
            'GATE_PASS' => $common + ['gate_pass_no' => 'GP-2026-0001', 'destination' => 'Approved off-campus location'],
            'BILLING_STATEMENT' => $common + ['billing_no' => 'BILL-2026-0001', 'due_date' => '20 September 2026', 'items.amount' => 'PHP 150.00', 'total_amount' => 'PHP 150.00'],
            'RSLDDP' => $common + ['incident_no' => 'INC-2026-0001', 'incident_type' => 'Damaged property', 'reported_date' => '10 September 2026', 'items.condition' => 'Damaged'],
            default => $common,
        };
    }

    /** @return array<string,mixed> */
    private function inspectPdf(string $bytes, bool $withLayoutAnalysis = false): array
    {
        if (! str_starts_with(ltrim($bytes), '%PDF-')) {
            throw ValidationException::withMessages(['template_file' => 'The uploaded PDF is unreadable or corrupt.']);
        }
        if (preg_match('/\/Encrypt\b/', $bytes) === 1) {
            throw ValidationException::withMessages(['template_file' => 'Encrypted PDFs cannot be activated. Upload an unlocked approved layout.']);
        }
        preg_match_all('/\((?:\\\\.|[^)]){1,180}\)/s', $bytes, $matches);
        $strings = array_map(function (string $value): string {
            $value = trim($value, '()');
            return trim(str_replace(['\\n', '\\r', '\\(', '\\)'], [' ', ' ', '(', ')'], $value));
        }, $matches[0] ?? []);
        preg_match_all('/\/T\s*\(([^)]+)\)/', $bytes, $fieldNames);
        $text = implode("\n", array_filter([...$strings, ...($fieldNames[1] ?? [])]));
        $pages = preg_match_all('/\/Type\s*\/Page\b/', $bytes);
        $widgets = $this->pdfFormWidgets($bytes);
        $layout = $withLayoutAnalysis ? $this->pdfLayoutAnalysis($bytes) : ['source' => 'NOT_REQUESTED', 'words' => [], 'lines' => []];

        return [
            'pages' => max(1, (int) $pages),
            'scanned' => trim($text) === '',
            'text' => $text,
            'labels' => $this->labelsFromText($text),
            'fillable_fields' => array_values(array_unique([...($fieldNames[1] ?? []), ...array_column($widgets, 'name')])),
            // This is structural metadata only. It is used internally to
            // prepare a production layout and is never shown to an admin.
            'fillable_widgets' => $widgets,
            'layout_source' => $layout['source'],
            'layout_words' => $layout['words'],
            'layout_lines' => $layout['lines'],
        ];
    }

    /**
     * Extract positioned words from the actual PDF representation. Text PDFs
     * use Poppler's bounding boxes; image-only PDFs fall back to Tesseract TSV
     * so both paths produce page-relative geometry for the same mapper.
     *
     * @return array{source:string,words:list<array<string,mixed>>,lines:list<array<string,mixed>>}
     */
    private function pdfLayoutAnalysis(string $bytes): array
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'spmu-layout-analysis-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return ['source' => 'UNAVAILABLE', 'words' => [], 'lines' => []];
        }
        $sourcePath = $directory.DIRECTORY_SEPARATOR.'approved-layout.pdf';

        try {
            if (file_put_contents($sourcePath, $bytes) === false) {
                return ['source' => 'UNAVAILABLE', 'words' => [], 'lines' => []];
            }
            @chmod($sourcePath, 0600);

            $textProcess = new Process(['pdftotext', '-bbox-layout', '-enc', 'UTF-8', $sourcePath, '-']);
            $textProcess->setTimeout(45);
            $textProcess->run();
            $words = $textProcess->isSuccessful() ? $this->parsePopplerBoundingBoxes($textProcess->getOutput()) : [];
            if ($words !== []) {
                return ['source' => 'TEXT_LAYER', 'words' => $words, 'lines' => $this->layoutLines($words)];
            }

            $images = $this->renderPdfPagesForAnalysis($sourcePath, $directory);
            $ocrWords = [];
            foreach ($images as $page => $image) {
                $ocr = new Process(['tesseract', $image, 'stdout', '-l', 'eng', 'tsv']);
                $ocr->setTimeout(60);
                $ocr->run();
                if ($ocr->isSuccessful()) {
                    $ocrWords = [...$ocrWords, ...$this->parseTesseractTsv($ocr->getOutput(), $page, $image)];
                }
            }

            return ['source' => $ocrWords === [] ? 'UNAVAILABLE' : 'OCR', 'words' => $ocrWords, 'lines' => $this->layoutLines($ocrWords)];
        } catch (Throwable) {
            return ['source' => 'UNAVAILABLE', 'words' => [], 'lines' => []];
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    /** @return list<array<string,mixed>> */
    private function parsePopplerBoundingBoxes(string $html): array
    {
        preg_match_all('/<page\b([^>]*)>(.*?)<\/page>/si', $html, $pages, PREG_SET_ORDER);
        $words = [];
        foreach ($pages as $pageIndex => $page) {
            $width = $this->xmlNumber($page[1], 'width');
            $height = $this->xmlNumber($page[1], 'height');
            if ($width === null || $height === null || $width <= 0 || $height <= 0) {
                continue;
            }
            preg_match_all('/<word\b([^>]*)>(.*?)<\/word>/si', $page[2], $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $left = $this->xmlNumber($match[1], 'xMin');
                $top = $this->xmlNumber($match[1], 'yMin');
                $right = $this->xmlNumber($match[1], 'xMax');
                $bottom = $this->xmlNumber($match[1], 'yMax');
                $text = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($left === null || $top === null || $right === null || $bottom === null || $text === '') {
                    continue;
                }
                $words[] = [
                    'page' => $pageIndex + 1,
                    'text' => $text,
                    'x' => round($left / $width * 100, 2),
                    'y' => round($top / $height * 100, 2),
                    'width' => round(max(0.2, ($right - $left) / $width * 100), 2),
                    'height' => round(max(0.2, ($bottom - $top) / $height * 100), 2),
                    'confidence' => 1.0,
                ];
            }
        }

        return $words;
    }

    /** @return list<array<string,mixed>> */
    private function parseTesseractTsv(string $tsv, int $page, string $image): array
    {
        $dimensions = @getimagesize($image);
        if (! is_array($dimensions) || ($dimensions[0] ?? 0) <= 0 || ($dimensions[1] ?? 0) <= 0) {
            return [];
        }
        $words = [];
        foreach (preg_split('/\R/', trim($tsv)) ?: [] as $lineNumber => $line) {
            if ($lineNumber === 0 || $line === '') {
                continue;
            }
            $columns = explode("\t", $line, 12);
            if (count($columns) < 12 || (int) $columns[0] !== 5) {
                continue;
            }
            $confidence = (float) $columns[10];
            $text = trim($columns[11]);
            if ($confidence < 35 || $text === '') {
                continue;
            }
            $words[] = [
                'page' => $page,
                'text' => $text,
                'x' => round((float) $columns[6] / $dimensions[0] * 100, 2),
                'y' => round((float) $columns[7] / $dimensions[1] * 100, 2),
                'width' => round(max(0.2, (float) $columns[8] / $dimensions[0] * 100), 2),
                'height' => round(max(0.2, (float) $columns[9] / $dimensions[1] * 100), 2),
                'confidence' => round($confidence / 100, 2),
            ];
        }

        return $words;
    }

    /** @return array<int,string> */
    private function renderPdfPagesForAnalysis(string $sourcePath, string $directory): array
    {
        $prefix = $directory.DIRECTORY_SEPARATOR.'page';
        $process = new Process(['pdftoppm', '-r', '150', '-png', $sourcePath, $prefix]);
        $process->setTimeout(90);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }
        $pages = [];
        foreach (glob($prefix.'-*.png') ?: [] as $path) {
            if (preg_match('/-(\d+)\.png$/', $path, $match) === 1) {
                $pages[(int) $match[1]] = $path;
            }
        }
        ksort($pages);

        return $pages;
    }

    /** @return list<array<string,mixed>> */
    private function layoutLines(array $words): array
    {
        usort($words, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y'], (float) $left['x']] <=> [(int) $right['page'], (float) $right['y'], (float) $right['x']]);
        $lines = [];
        foreach ($words as $word) {
            $last = array_key_last($lines);
            if ($last === null
                || (int) $lines[$last]['page'] !== (int) $word['page']
                || abs((float) $lines[$last]['y'] - (float) $word['y']) > max(0.9, (float) $word['height'])) {
                $lines[] = ['page' => (int) $word['page'], 'text' => (string) $word['text'], 'x' => (float) $word['x'], 'y' => (float) $word['y'], 'width' => (float) $word['width'], 'height' => (float) $word['height'], 'confidence' => (float) $word['confidence']];
                continue;
            }
            $line = &$lines[$last];
            $line['text'] .= ' '.(string) $word['text'];
            $right = max((float) $line['x'] + (float) $line['width'], (float) $word['x'] + (float) $word['width']);
            $bottom = max((float) $line['y'] + (float) $line['height'], (float) $word['y'] + (float) $word['height']);
            $line['x'] = min((float) $line['x'], (float) $word['x']);
            $line['y'] = min((float) $line['y'], (float) $word['y']);
            $line['width'] = $right - (float) $line['x'];
            $line['height'] = $bottom - (float) $line['y'];
            $line['confidence'] = min((float) $line['confidence'], (float) $word['confidence']);
            unset($line);
        }

        return array_slice($lines, 0, 500);
    }

    /**
     * Form labels often share a printed header row. Generate short, bounded
     * phrases from that row so "Quantity" and "Unit" retain distinct x
     * positions instead of inheriting one combined line rectangle.
     *
     * @param list<array<string,mixed>> $words
     * @return list<array<string,mixed>>
     */
    private function layoutPhrases(array $words): array
    {
        usort($words, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y'], (float) $left['x']] <=> [(int) $right['page'], (float) $right['y'], (float) $right['x']]);
        $wordLines = [];
        foreach ($words as $word) {
            $last = array_key_last($wordLines);
            if ($last === null
                || (int) $wordLines[$last]['page'] !== (int) $word['page']
                || abs((float) $wordLines[$last]['y'] - (float) $word['y']) > max(0.9, (float) $word['height'])) {
                $wordLines[] = ['page' => (int) $word['page'], 'y' => (float) $word['y'], 'words' => [$word]];
                continue;
            }
            $wordLines[$last]['words'][] = $word;
        }

        $phrases = [];
        foreach ($wordLines as $line) {
            $lineWords = $line['words'];
            usort($lineWords, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);
            $count = count($lineWords);
            for ($start = 0; $start < $count; $start++) {
                $text = '';
                $left = (float) $lineWords[$start]['x'];
                $top = (float) $lineWords[$start]['y'];
                $right = $left;
                $bottom = $top;
                $confidence = 1.0;
                for ($end = $start; $end < min($count, $start + 6); $end++) {
                    $word = $lineWords[$end];
                    $text .= ($end === $start ? '' : ' ').(string) $word['text'];
                    $right = max($right, (float) $word['x'] + (float) $word['width']);
                    $bottom = max($bottom, (float) $word['y'] + (float) $word['height']);
                    $confidence = min($confidence, (float) $word['confidence']);
                    $phrases[] = [
                        'page' => (int) $line['page'],
                        'text' => $text,
                        'x' => $left,
                        'y' => $top,
                        'width' => $right - $left,
                        'height' => $bottom - $top,
                        'confidence' => $confidence,
                    ];
                }
            }
        }

        return array_slice($phrases, 0, 2000);
    }

    /**
     * Poppler emits stacked table labels as separate visual rows. Combine
     * nearby, horizontally-overlapping fragments (for example, "Expected
     * Date of" above "Return") so the matcher can recognize a complete
     * header without depending on a fixed form coordinate.
     *
     * @param list<array<string,mixed>> $words
     * @return list<array<string,mixed>>
     */
    private function multilineHeaderPhrases(array $words): array
    {
        usort($words, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y'], (float) $left['x']] <=> [(int) $right['page'], (float) $right['y'], (float) $right['x']]);
        $rows = [];
        foreach ($words as $word) {
            $last = array_key_last($rows);
            if ($last === null
                || (int) $rows[$last]['page'] !== (int) $word['page']
                || abs((float) $rows[$last]['y'] - (float) $word['y']) > 0.45) {
                $rows[] = ['page' => (int) $word['page'], 'y' => (float) $word['y'], 'words' => [$word]];
                continue;
            }
            $rows[$last]['words'][] = $word;
        }

        $phrases = [];
        foreach ($rows as $topIndex => $topRow) {
            $topWords = $topRow['words'];
            usort($topWords, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);
            for ($bottomIndex = $topIndex + 1; $bottomIndex < count($rows); $bottomIndex++) {
                $bottomRow = $rows[$bottomIndex];
                if ((int) $bottomRow['page'] !== (int) $topRow['page'] || (float) $bottomRow['y'] - (float) $topRow['y'] > 2.5) {
                    break;
                }
                $bottomWords = $bottomRow['words'];
                usort($bottomWords, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);

                foreach ($this->boundedWordPhrases($topWords, (int) $topRow['page']) as $top) {
                    foreach ($this->boundedWordPhrases($bottomWords, (int) $bottomRow['page']) as $bottom) {
                        $topRight = (float) $top['x'] + (float) $top['width'];
                        $bottomRight = (float) $bottom['x'] + (float) $bottom['width'];
                        if (min($topRight, $bottomRight) < max((float) $top['x'], (float) $bottom['x']) - 0.8) {
                            continue;
                        }
                        $left = min((float) $top['x'], (float) $bottom['x']);
                        $right = max($topRight, $bottomRight);
                        $bottomEdge = max((float) $top['y'] + (float) $top['height'], (float) $bottom['y'] + (float) $bottom['height']);
                        $phrases[] = [
                            'page' => (int) $topRow['page'],
                            'text' => trim((string) $top['text'].' '.(string) $bottom['text']),
                            'x' => $left,
                            'y' => (float) $top['y'],
                            'width' => $right - $left,
                            'height' => $bottomEdge - (float) $top['y'],
                            'confidence' => min((float) $top['confidence'], (float) $bottom['confidence']),
                        ];
                    }
                }
            }
        }

        return array_slice($phrases, 0, 2000);
    }

    /** @return list<array<string,mixed>> */
    private function boundedWordPhrases(array $words, int $page): array
    {
        $phrases = [];
        $count = count($words);
        for ($start = 0; $start < $count; $start++) {
            $text = '';
            $left = (float) $words[$start]['x'];
            $top = (float) $words[$start]['y'];
            $right = $left;
            $bottom = $top;
            $confidence = 1.0;
            for ($end = $start; $end < min($count, $start + 4); $end++) {
                $word = $words[$end];
                $text .= ($end === $start ? '' : ' ').(string) $word['text'];
                $right = max($right, (float) $word['x'] + (float) $word['width']);
                $bottom = max($bottom, (float) $word['y'] + (float) $word['height']);
                $confidence = min($confidence, (float) $word['confidence']);
                $phrases[] = [
                    'page' => $page,
                    'text' => $text,
                    'x' => $left,
                    'y' => $top,
                    'width' => $right - $left,
                    'height' => $bottom - $top,
                    'confidence' => $confidence,
                ];
            }
        }

        return $phrases;
    }

    private function xmlNumber(string $attributes, string $attribute): ?float
    {
        if (preg_match('/\b'.preg_quote($attribute, '/').'="([-+]?\d*\.?\d+)"/i', $attributes, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }

    /**
     * Build production positions from named PDF form widgets when present.
     * Flat and scanned PDFs are mapped separately from visible labels and
     * their detected geometry.
     *
     * @param array<string,mixed> $review
     * @return array{mappings:list<array<string,mixed>>,table_layouts:array<string,array{row_height_percent:float,max_rows:int}>}
     */
    private function automaticPdfFormFieldMappings(string $type, array $review): array
    {
        $definitions = $this->fieldDefinitions($type);
        $widgetsByField = [];

        foreach ($review['fillable_widgets'] ?? [] as $widget) {
            if (! is_array($widget)) {
                continue;
            }
            $field = $this->fieldForSystemMarker((string) ($widget['name'] ?? ''), $definitions);
            if ($field === null || ($definitions[$field]['manual'] ?? true)) {
                continue;
            }
            if (! is_numeric($widget['page'] ?? null) || ! is_numeric($widget['x'] ?? null) || ! is_numeric($widget['y'] ?? null)) {
                continue;
            }
            $widgetsByField[$field][] = $widget;
        }

        $mappings = [];
        foreach ($widgetsByField as $field => $widgets) {
            usort($widgets, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y'], (float) $left['x']] <=> [(int) $right['page'], (float) $right['y'], (float) $right['x']]);
            $widget = $widgets[0];
            $mappings[] = $this->mapping(
                $field,
                'click:automatic-pdf-form-field',
                'PDF_FORM_FIELD',
                1.0,
                $definitions[$field]['label'],
                str_starts_with($field, 'items.'),
                (int) $widget['page'],
                (float) $widget['x'],
                (float) $widget['y'],
            );
        }

        return [
            'mappings' => $mappings,
            'table_layouts' => $this->automaticPdfTableLayouts($definitions, $widgetsByField),
        ];
    }

    /**
     * Map the system-backed fields that are unique to the approved Borrower's
     * Slip. This is deliberately separate from generic PDF layout extraction:
     * it derives positions from the form's own visible labels and rows, never
     * from fixed page coordinates or from signature imagery.
     *
     * @param list<array<string,mixed>> $baseMappings
     * @return array{mappings:list<array<string,mixed>>,table_layouts:array<string,array<string,float|int>>}
     */
    private function automaticBorrowerSlipSystemMappings(array $review, array $baseMappings): array
    {
        $words = is_array($review['layout_words'] ?? null) ? $review['layout_words'] : [];
        if ($words === []) {
            return ['mappings' => [], 'table_layouts' => []];
        }

        $mappings = [];
        $add = function (string $field, array $anchor, float $x, float $y, string $label, ?float $maxWidth = null) use (&$mappings): void {
            $mapping = $this->mapping(
                $field,
                'click:automatic-borrower-slip-layout',
                'PDF_BORROWER_SLIP_SYSTEM_REGION',
                0.98,
                $label,
                false,
                (int) $anchor['page'],
                round($x, 2),
                round($y, 2),
            );
            if ($maxWidth !== null) {
                $mapping['max_width_percent'] = round($maxWidth, 2);
            }
            $mappings[] = $mapping;
        };

        $topWords = array_values(array_filter($words, fn (array $word): bool => (float) ($word['y'] ?? 100) < 18));
        $firstWord = function (callable $predicate) use ($topWords): ?array {
            foreach ($topWords as $word) {
                if ($predicate($word)) {
                    return $word;
                }
            }

            return null;
        };
        $dateBlank = $firstWord(fn (array $word): bool => str_contains((string) ($word['text'] ?? ''), '_'));
        if ($dateBlank) {
            $add('document_date', $dateBlank, (float) $dateBlank['x'], (float) $dateBlank['y'], 'Document Date', (float) $dateBlank['width']);
        }
        $employee = $firstWord(fn (array $word): bool => $this->normalise((string) ($word['text'] ?? '')) === 'employee');
        if ($employee) {
            $add('employee_checkbox', $employee, max(0.0, (float) $employee['x'] - 1.12), (float) $employee['y'] - 0.1, 'Employee checkbox', 0.95);
        }
        $others = $firstWord(fn (array $word): bool => $this->normalise((string) ($word['text'] ?? '')) === 'others');
        if ($others) {
            $add('others_checkbox', $others, max(0.0, (float) $others['x'] - 1.12), (float) $others['y'] - 0.1, 'Others checkbox', 0.95);
        }
        $otherBlank = $firstWord(fn (array $word): bool => str_contains((string) ($word['text'] ?? ''), '_') && $dateBlank !== $word);
        if ($otherBlank) {
            $add('other_classification', $otherBlank, (float) $otherBlank['x'], (float) $otherBlank['y'], 'Others classification', (float) $otherBlank['width']);
        }

        $phrases = array_values(array_filter($this->layoutPhrases($words), function (array $phrase): bool {
            return (float) ($phrase['y'] ?? 0) >= 63 && (float) ($phrase['y'] ?? 100) <= 67;
        }));
        $allowedHeaders = ['borrowed by', 'approved by', 'issued by', 'received by', 'returned by', 'verified by'];
        $headers = array_values(array_filter($phrases, fn (array $phrase): bool => in_array($this->normalise((string) $phrase['text']), $allowedHeaders, true)));
        usort($headers, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);
        $headerFor = function (string $label, bool $returnSection = false) use ($headers): ?array {
            foreach ($headers as $header) {
                if ($this->normalise((string) $header['text']) === $label
                    && ($returnSection ? (float) $header['x'] > 70 : (float) $header['x'] < 60)) {
                    return $header;
                }
            }

            return null;
        };
        $rowFor = function (string $label) use ($words): ?array {
            foreach ($words as $word) {
                if ((float) ($word['y'] ?? 0) >= 67
                    && (float) ($word['y'] ?? 100) <= 75
                    && $this->normalise((string) ($word['text'] ?? '')) === $label) {
                    return $word;
                }
            }

            return null;
        };
        $printedNameRow = $rowFor('printed');
        $designationRow = $rowFor('designation');
        $dateRow = $rowFor('date');
        $signatories = [
            'borrowed_by' => $headerFor('borrowed by'),
            'approved_by' => $headerFor('approved by'),
            'issued_by' => $headerFor('issued by'),
            'return_received_by' => $headerFor('received by', true),
        ];
        foreach ($signatories as $prefix => $header) {
            if (! $header || ! $printedNameRow || ! $designationRow || ! $dateRow) {
                continue;
            }
            $start = max(0.0, (float) $header['x'] - max(3.0, (float) $header['width'] * 0.82));
            $next = collect($headers)->first(fn (array $candidate): bool => (float) $candidate['x'] > (float) $header['x']);
            $width = is_array($next)
                ? max(6.0, (float) $next['x'] - $start - 1.2)
                : max(6.0, 96.0 - $start);
            $add($prefix.'_printed_name', $header, $start, (float) $printedNameRow['y'], ucwords(str_replace('_', ' ', $prefix)).' printed name', $width);
            $add($prefix.'_designation', $header, $start, (float) $designationRow['y'], ucwords(str_replace('_', ' ', $prefix)).' designation', $width);
            $add($prefix.'_date', $header, $start, (float) $dateRow['y'], ucwords(str_replace('_', ' ', $prefix)).' date', $width);
        }

        $releaseMappings = collect($baseMappings)
            ->filter(fn (array $mapping): bool => in_array((string) ($mapping['field'] ?? ''), ['date_released', 'release_time', 'date_returned', 'remarks'], true))
            ->values()
            ->all();
        $tableLayouts = [];
        if (count($releaseMappings) === 4) {
            $firstRowY = max(array_map(fn (array $mapping): float => (float) $mapping['y'], $releaseMappings));
            $page = (int) $releaseMappings[0]['page'];
            $nextSectionY = min(array_filter(array_map(
                fn (array $line): ?float => (int) ($line['page'] ?? 0) === $page
                    && (float) ($line['y'] ?? 0) > $firstRowY + 1.2
                    ? (float) $line['y']
                    : null,
                $review['layout_lines'] ?? [],
            )) ?: [0.0]);
            $availableHeight = $nextSectionY - $firstRowY - 2.6;
            if ($availableHeight >= 2.2) {
                $tableLayouts['release_return'] = [
                    'row_height_percent' => 1.8,
                    'max_rows' => 1,
                    'max_height_percent' => round($availableHeight, 2),
                ];
            }
        }

        return ['mappings' => $mappings, 'table_layouts' => $tableLayouts];
    }

    /**
     * Infer writable regions from visible labels and their page geometry. This
     * intentionally runs before FPDI rendering and stores only internal
     * percentages, never exposing implementation coordinates to an admin.
     *
     * @param list<array<string,mixed>> $existingMappings
     * @return array{mappings:list<array<string,mixed>>,table_layouts:array<string,array{row_height_percent:float,max_rows:int}>}
     */
    private function automaticPdfLayoutMappings(string $type, array $review, array $existingMappings): array
    {
        $definitions = $this->fieldDefinitions($type);
        $existing = collect($existingMappings)->pluck('field')->filter()->all();
        $lines = ! empty($review['layout_words'])
            ? [
                ...$this->layoutPhrases($review['layout_words']),
                ...$this->multilineHeaderPhrases($review['layout_words']),
            ]
            : array_values(array_filter($review['layout_lines'] ?? [], fn ($line): bool => is_array($line) && filled($line['text'] ?? null)));
        if ($lines === []) {
            return ['mappings' => [], 'table_layouts' => []];
        }

        $candidates = [];
        foreach ($definitions as $field => $definition) {
            // Keep wet-signature and approval areas out of automatic output.
            // Optional fields that belong to a printed system table (such as
            // release/return data and remarks) are mapped when clearly found.
            if ($definition['manual']
                || (! $definition['required'] && $definition['table'] === null)
                || in_array($field, $existing, true)) {
                continue;
            }
            $candidate = $this->bestLayoutLabel($field, $definition, $lines);
            if ($candidate !== null) {
                $candidates[$field] = $candidate;
            }
        }

        $mappings = [];
        foreach ($candidates as $field => $candidate) {
            $fieldDefinition = $definitions[$field];
            $sameHeaderLine = array_filter(
                $candidates,
                fn (array $other, string $otherField): bool => ($definitions[$otherField]['table'] ?? null) === $fieldDefinition['table']
                    && (int) $other['page'] === (int) $candidate['page']
                    && abs((float) $other['y'] - (float) $candidate['y']) <= 0.75,
                ARRAY_FILTER_USE_BOTH,
            );
            if (! empty($candidate['is_value_marker'])) {
                $x = (float) $candidate['x'];
                $y = (float) $candidate['y'];
                $method = 'PDF_VALUE_MARKER';
            } elseif ($fieldDefinition['table'] !== null && count($sameHeaderLine) >= 2) {
                $y = min(96.0, max(array_map(fn (array $other): float => (float) $other['y'] + (float) $other['height'], $sameHeaderLine)) + 1.4);
                $x = (float) $candidate['x'];
                $method = 'PDF_TABLE_HEADER';
            } elseif ($field === 'remarks') {
                $x = (float) $candidate['x'];
                $y = min(96.0, (float) $candidate['y'] + (float) $candidate['height'] + 1.2);
                $method = 'PDF_LABEL_BELOW';
            } else {
                $x = min(96.0, (float) $candidate['x'] + (float) $candidate['width'] + 1.8);
                $y = (float) $candidate['y'];
                $method = 'PDF_LABEL_VALUE_REGION';
            }
            $mappings[] = $this->mapping(
                $field,
                'click:automatic-layout-analysis',
                $method,
                (float) $candidate['confidence'],
                (string) $candidate['text'],
                str_starts_with($field, 'items.'),
                (int) $candidate['page'],
                round($x, 2),
                round($y, 2),
            );
        }

        return [
            'mappings' => $mappings,
            'table_layouts' => $this->automaticLayoutTableLayouts($definitions, $mappings, $lines),
        ];
    }

    /**
     * @param array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string} $definition
     * @param list<array<string,mixed>> $lines
     * @return array<string,mixed>|null
     */
    private function bestLayoutLabel(string $field, array $definition, array $lines): ?array
    {
        $best = null;
        foreach ($lines as $line) {
            $hasExplicitMarker = str_contains((string) $line['text'], '{{');
            $markerField = $hasExplicitMarker
                ? $this->fieldForSystemMarker((string) $line['text'], [$field => $definition])
                : null;
            if ($hasExplicitMarker && $markerField !== $field) {
                continue;
            }
            $score = 0.0;
            foreach ([$definition['label'], ...$definition['aliases']] as $index => $alias) {
                $candidateScore = $this->labelConfidence((string) $line['text'], $alias);
                // Prefer the complete schema label to a short alias when both
                // appear on the same printed header line.
                if ($index === 0 && $candidateScore >= 0.98) {
                    $candidateScore += 0.01;
                }
                $score = max($score, $candidateScore);
            }
            $score *= max(0.5, (float) ($line['confidence'] ?? 1.0));
            if ($score < 0.84 || ($best !== null && (
                $score < (float) $best['confidence']
                || (abs($score - (float) $best['confidence']) < 0.005 && (float) $line['y'] <= (float) $best['y'])
            ))) {
                continue;
            }
            $isValueMarker = $markerField === $field;
            $best = [...$line, 'confidence' => round($score, 2), 'is_value_marker' => $isValueMarker];
        }

        return $best;
    }

    /**
     * The writable table body begins beneath the detected column headers. Its
     * lower boundary is the next visible section on the actual form, which
     * gives a conservative row capacity without an administrator entering
     * row geometry. If a lower boundary cannot be found, preparation must
     * fail rather than allow generated rows to overlap the form.
     *
     * @param array<string,array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}> $definitions
     * @param list<array<string,mixed>> $mappings
     * @param list<array<string,mixed>> $layoutLines
     * @return array<string,array{row_height_percent:float,max_rows:int}>
     */
    private function automaticLayoutTableLayouts(array $definitions, array $mappings, array $layoutLines): array
    {
        $mappedByField = collect($mappings)->keyBy('field');
        $layouts = [];
        foreach (array_unique(array_filter(array_map(
            fn (array $definition, string $field): ?string => str_starts_with($field, 'items.') ? $definition['table'] : null,
            $definitions,
            array_keys($definitions),
        ))) as $table) {
            $itemFields = array_keys(array_filter($definitions, fn (array $definition, string $field): bool => $definition['required'] && $definition['table'] === $table && str_starts_with($field, 'items.'), ARRAY_FILTER_USE_BOTH));
            $itemMappings = array_values(array_filter(array_map(fn (string $field) => $mappedByField->get($field), $itemFields)));
            if (count($itemMappings) !== count($itemFields)) {
                continue;
            }
            $page = (int) $itemMappings[0]['page'];
            $firstRowY = max(array_map(fn (array $mapping): float => (float) $mapping['y'], $itemMappings));
            $nextSectionY = min(array_filter(array_map(
                fn (array $line): ?float => (int) ($line['page'] ?? 0) === $page
                    && filled($line['text'] ?? null)
                    && (float) ($line['y'] ?? 0) > $firstRowY + 1.2
                    ? (float) $line['y']
                    : null,
                $layoutLines,
            )) ?: [0.0]);

            // A following label normally sits below the table rule. Keep a
            // clearance band for that rule and label before calculating rows.
            $availableHeight = $nextSectionY - $firstRowY - 2.6;
            if ($availableHeight < 2.2) {
                continue;
            }
            $maxRows = max(1, min(20, (int) floor($availableHeight / 1.8)));
            $rowHeight = min(6.0, max(1.8, $availableHeight / $maxRows));
            $layouts[$table] = [
                'row_height_percent' => round($rowHeight, 2),
                'max_rows' => $maxRows,
                'max_height_percent' => round($availableHeight, 2),
            ];
        }

        return $layouts;
    }

    /**
     * A repeating table is usable only when every required item column has at
     * least two named rows on one page. This establishes both row spacing and
     * safe visible capacity without an administrator entering either value.
     *
     * @param array<string,array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}> $definitions
     * @param array<string,list<array<string,mixed>>> $widgetsByField
     * @return array<string,array{row_height_percent:float,max_rows:int}>
     */
    private function automaticPdfTableLayouts(array $definitions, array $widgetsByField): array
    {
        $layouts = [];
        foreach (array_unique(array_filter(array_map(
            fn (array $definition, string $field): ?string => str_starts_with($field, 'items.') ? $definition['table'] : null,
            $definitions,
            array_keys($definitions),
        ))) as $table) {
            $fields = array_keys(array_filter($definitions, fn (array $definition, string $field): bool => $definition['required'] && $definition['table'] === $table && str_starts_with($field, 'items.'), ARRAY_FILTER_USE_BOTH));
            if ($fields === []) {
                continue;
            }

            $rowsByField = [];
            foreach ($fields as $field) {
                $rows = array_values(array_filter($widgetsByField[$field] ?? [], fn (array $widget): bool => is_numeric($widget['page'] ?? null) && is_numeric($widget['y'] ?? null)));
                usort($rows, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y']] <=> [(int) $right['page'], (float) $right['y']]);
                if (count($rows) < 2 || (int) $rows[0]['page'] !== (int) $rows[1]['page']) {
                    continue 2;
                }
                $rowsByField[$field] = $rows;
            }

            $firstFieldRows = reset($rowsByField);
            $first = $firstFieldRows[0];
            $second = $firstFieldRows[1];
            $rowHeight = (float) $second['y'] - (float) $first['y'];
            if ($rowHeight <= 0 || $rowHeight > 25) {
                continue;
            }

            $samePageCounts = array_map(
                fn (array $rows): int => count(array_filter($rows, fn (array $row): bool => (int) $row['page'] === (int) $first['page'])),
                $rowsByField,
            );
            $maxRows = min($samePageCounts);
            if ($maxRows < 2) {
                continue;
            }
            $layouts[$table] = [
                'row_height_percent' => round($rowHeight, 2),
                'max_rows' => $maxRows,
                'max_height_percent' => round($rowHeight * $maxRows, 2),
            ];
        }

        return $layouts;
    }

    /**
     * Read enough uncompressed AcroForm metadata to make a deterministic
     * decision. PDFs using object streams remain valid uploads, but are not
     * treated as system-ready unless their field geometry is available.
     *
     * @return list<array{name:string,page:int,x:float,y:float}>
     */
    private function pdfFormWidgets(string $bytes): array
    {
        preg_match_all('/(\d+)\s+\d+\s+obj\b(.*?)\bendobj\b/s', $bytes, $objectMatches, PREG_SET_ORDER);
        $objects = [];
        foreach ($objectMatches as $match) {
            $objects[(int) $match[1]] = (string) $match[2];
        }
        if ($objects === []) {
            return [];
        }

        $defaultBox = $this->pdfMediaBox($bytes);
        $pages = [];
        foreach ($objects as $number => $object) {
            if (preg_match('/\/Type\s*\/Page\b/', $object) !== 1) {
                continue;
            }
            $pages[$number] = [
                'page' => count($pages) + 1,
                'box' => $this->pdfMediaBox($object) ?? $defaultBox,
            ];
        }

        $widgets = [];
        foreach ($objects as $object) {
            if (preg_match('/\/Subtype\s*\/Widget\b/', $object) !== 1) {
                continue;
            }
            $name = $this->pdfFieldName($object);
            if ($name === null && preg_match('/\/Parent\s+(\d+)\s+\d+\s+R/', $object, $parent) === 1) {
                $name = $this->pdfFieldName($objects[(int) $parent[1]] ?? '');
            }
            $rect = $this->pdfRect($object);
            $pageObject = preg_match('/\/P\s+(\d+)\s+\d+\s+R/', $object, $pageReference) === 1 ? (int) $pageReference[1] : null;
            $page = $pageObject !== null ? ($pages[$pageObject] ?? null) : (count($pages) === 1 ? reset($pages) : null);
            if ($name === null || $rect === null || ! is_array($page) || ! is_array($page['box'])) {
                continue;
            }
            [$left, $bottom, $right, $top] = $rect;
            [$boxLeft, $boxBottom, $boxRight, $boxTop] = $page['box'];
            $width = $boxRight - $boxLeft;
            $height = $boxTop - $boxBottom;
            if ($width <= 0 || $height <= 0) {
                continue;
            }
            $widgets[] = [
                'name' => $name,
                'page' => (int) $page['page'],
                'x' => round((min($left, $right) - $boxLeft) / $width * 100, 2),
                'y' => round(($boxTop - max($bottom, $top)) / $height * 100, 2),
            ];
        }

        return $widgets;
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function pdfMediaBox(string $pdf): ?array
    {
        if (preg_match('/\/MediaBox\s*\[\s*([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s*\]/', $pdf, $match) !== 1) {
            return null;
        }

        return [(float) $match[1], (float) $match[2], (float) $match[3], (float) $match[4]];
    }

    /** @return array{0:float,1:float,2:float,3:float}|null */
    private function pdfRect(string $object): ?array
    {
        if (preg_match('/\/Rect\s*\[\s*([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s+([-+]?\d*\.?\d+)\s*\]/', $object, $match) !== 1) {
            return null;
        }

        return [(float) $match[1], (float) $match[2], (float) $match[3], (float) $match[4]];
    }

    private function pdfFieldName(string $object): ?string
    {
        if (preg_match('/\/T\s*\(((?:\\\\.|[^)])+)\)/s', $object, $match) === 1) {
            return trim(str_replace(['\\\\(', '\\\\)', '\\\\n', '\\\\r'], ['(', ')', ' ', ' '], $match[1]));
        }
        if (preg_match('/\/T\s*\/([^\s\/>\[\]()]+)/', $object, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /**
     * @param array<string,array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string}> $definitions
     */
    private function fieldForSystemMarker(string $marker, array $definitions): ?string
    {
        $marker = trim($marker, " \t\n\r\0\x0B{}");
        $candidates = [$marker];
        $withoutRowNumber = preg_replace('/(?:[._\-\s]|\[)?(?:row)?\d+\]?$/i', '', $marker);
        if (is_string($withoutRowNumber) && $withoutRowNumber !== $marker) {
            $candidates[] = $withoutRowNumber;
        }

        foreach ($candidates as $candidate) {
            $normalisedCandidate = $this->normalise($candidate);
            foreach ($definitions as $field => $definition) {
                $markers = [$field, $definition['label'], ...$definition['aliases']];
                if ($definition['table'] !== null) {
                    $markers[] = str_starts_with($field, 'items.') ? $field : 'items.'.$field;
                    foreach ($definition['aliases'] as $alias) {
                        $markers[] = 'items.'.$alias;
                    }
                }
                foreach ($markers as $knownMarker) {
                    if ($normalisedCandidate !== '' && $normalisedCandidate === $this->normalise($knownMarker)) {
                        return $field;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function inspectDocx(string $bytes): array
    {
        [$zip, $path] = $this->openZip($bytes, 'DOCX');
        try {
            $document = $zip->getFromName('word/document.xml');
            if ($document === false) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded DOCX is missing its Word document content.']);
            }
            $xml = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_starts_with($name, 'word/') && str_ends_with($name, '.xml')) {
                    $xml .= (string) $zip->getFromIndex($i);
                }
            }
            preg_match_all('/\{\{[a-z0-9_.]+\}\}/i', $xml, $matches);
            preg_match_all('/(?:w:tag|w:alias)\s+w:val="([^"]+)"/i', $xml, $controls);
            $text = trim(html_entity_decode(strip_tags(preg_replace('/<w:tab[^>]*\/>/i', ' ', $xml) ?? $xml)));
            return ['placeholders' => array_values(array_unique($matches[0] ?? [])), 'content_controls' => array_values(array_unique($controls[1] ?? [])), 'has_tables' => str_contains($xml, '<w:tbl'), 'text' => $text, 'labels' => $this->labelsFromText($text)];
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    private function inspectXlsx(string $bytes): array
    {
        [$zip, $path] = $this->openZip($bytes, 'XLSX');
        try {
            if ($zip->getFromName('[Content_Types].xml') === false) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded XLSX is unreadable or corrupt.']);
            }
            $shared = $this->sharedStrings((string) ($zip->getFromName('xl/sharedStrings.xml') ?: ''));
            $labels = [];
            $worksheets = [];
            $tableNames = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $name, $match) !== 1) {
                    continue;
                }
                $sheetName = 'Sheet '.$match[1];
                $worksheets[] = $sheetName;
                $sheetXml = (string) $zip->getFromIndex($i);
                preg_match_all('/<c\b[^>]*\br="([A-Z]+\d+)"([^>]*)>(.*?)<\/c>/is', $sheetXml, $cells, PREG_SET_ORDER);
                foreach ($cells as $cell) {
                    $value = $this->xlsxCellValue($cell[2], $cell[3], $shared);
                    if ($value !== '') {
                        $labels[] = ['text' => $value, 'location' => $sheetName.'!'.$cell[1]];
                    }
                }
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && str_starts_with($name, 'xl/tables/') && str_ends_with($name, '.xml')) {
                    $table = (string) $zip->getFromIndex($i);
                    if (preg_match('/\bdisplayName="([^"]+)"/i', $table, $match) === 1) {
                        $tableNames[] = html_entity_decode($match[1]);
                    }
                }
            }
            if ($worksheets === []) {
                throw ValidationException::withMessages(['template_file' => 'The uploaded XLSX has no readable worksheets.']);
            }
            return ['worksheets' => $worksheets, 'labels' => $labels, 'text' => implode("\n", array_column($labels, 'text')), 'tables' => array_values(array_unique($tableNames))];
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** @param array{label:string,aliases:list<string>,required:bool,manual:bool,table:?string} $definition @param array<string,mixed> $review @return array{target:string,method:string,confidence:float,location_label:string}|null */
    private function detectField(string $field, array $definition, string $format, array $review): ?array
    {
        $token = '{{'.$field.'}}';
        if ($format === 'DOCX' && in_array($token, array_map('strval', $review['placeholders'] ?? []), true)) {
            return ['target' => $token, 'method' => 'DOCX_PLACEHOLDER', 'confidence' => 1.0, 'location_label' => $definition['label']];
        }
        foreach ($review['labels'] ?? [] as $sourceLabel) {
            $text = is_array($sourceLabel) ? (string) ($sourceLabel['text'] ?? '') : (string) $sourceLabel;
            $location = is_array($sourceLabel) ? (string) ($sourceLabel['location'] ?? '') : '';
            foreach ($definition['aliases'] as $alias) {
                $score = $this->labelConfidence($text, $alias);
                if ($score < 0.8) {
                    continue;
                }
                return ['target' => $format === 'XLSX' && $location !== '' ? $location : 'anchor:'.$text, 'method' => $format.'_LABEL', 'confidence' => $score, 'location_label' => $text];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function mapping(string $field, string $target, string $method, float $confidence, string $locationLabel, bool $repeating, ?int $page = null, ?float $x = null, ?float $y = null): array
    {
        return ['field' => $field, 'target' => $target, 'method' => $method, 'confidence' => round($confidence, 2), 'location_label' => $locationLabel, 'page' => $page, 'x' => $x, 'y' => $y, 'repeating' => $repeating];
    }

    /** @return list<array{text:string,location:string}> */
    private function labelsFromText(string $text): array
    {
        $chunks = preg_split('/[\r\n]+|(?<=[.:;])\s{2,}/', $text) ?: [];
        $labels = [];
        foreach ($chunks as $chunk) {
            $chunk = trim(preg_replace('/\s+/', ' ', $chunk) ?? '');
            if ($chunk !== '' && mb_strlen($chunk) <= 180) {
                $labels[] = ['text' => $chunk, 'location' => ''];
            }
        }
        return array_slice(array_values(array_unique($labels, SORT_REGULAR)), 0, 250);
    }

    private function labelConfidence(string $source, string $alias): float
    {
        $source = $this->normalise($source);
        $alias = $this->normalise($alias);
        if ($source === '' || $alias === '') {
            return 0.0;
        }
        if ($source === $alias) {
            return 0.98;
        }
        if (str_contains($source, $alias)) {
            return 0.92;
        }
        $sourceWords = array_filter(explode(' ', $source));
        $aliasWords = array_filter(explode(' ', $alias));
        return count($aliasWords) > 0 && count(array_intersect($sourceWords, $aliasWords)) === count($aliasWords) ? 0.84 : 0.0;
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? '');
    }

    /** @return list<string> */
    private function sharedStrings(string $xml): array
    {
        preg_match_all('/<si\b[^>]*>(.*?)<\/si>/is', $xml, $items);
        return array_map(fn (string $item): string => trim(html_entity_decode(strip_tags($item))), $items[1] ?? []);
    }

    /** @param list<string> $shared */
    private function xlsxCellValue(string $attributes, string $content, array $shared): string
    {
        if (preg_match('/\bt="s"/i', $attributes) === 1 && preg_match('/<v>(\d+)<\/v>/i', $content, $match) === 1) {
            return (string) ($shared[(int) $match[1]] ?? '');
        }
        if (preg_match('/<t[^>]*>(.*?)<\/t>/is', $content, $match) === 1) {
            return trim(html_entity_decode(strip_tags($match[1])));
        }
        return preg_match('/<v>(.*?)<\/v>/is', $content, $match) === 1 ? trim(html_entity_decode(strip_tags($match[1]))) : '';
    }

    /** @return array{0:ZipArchive,1:string} */
    private function openZip(string $bytes, string $format): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['template_file' => "$format validation requires the PHP ZIP extension."]);
        }
        $path = tempnam(sys_get_temp_dir(), 'spmu-layout-');
        if ($path === false) {
            throw ValidationException::withMessages(['template_file' => 'Could not create a temporary layout-validation file.']);
        }
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            @unlink($path);
            throw ValidationException::withMessages(['template_file' => "The uploaded $format layout is unreadable or corrupt."]);
        }
        return [$zip, $path];
    }
}

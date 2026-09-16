<?php

namespace App\Services;

use App\Models\DocumentTemplate;

/**
 * Builds the data contract that an unactivated Office-template draft may use
 * for its protected generated-sample preview.
 *
 * This is deliberately a boundary, not a model serializer: it accepts only
 * the explicit semantic payload already produced by DocumentService. No
 * Eloquent model, relationship, database column collection, signature image,
 * file reference, or runtime value is persisted to dynamic_schema.
 */
class OfficialFormRuntimeContextService
{
    /** @var list<string> */
    private const OFFICE_FORMATS = ['DOCX', 'XLSX'];

    /** @var list<string> */
    private const PROTECTED_STATES = ['ACTIVE', 'HISTORICAL', 'FINALIZED'];

    /** @var list<string> */
    private const NAMESPACES = [
        'borrower',
        'request',
        'custody',
        'items',
        'approval',
        'release',
        'return',
        'laundry',
        'accountability',
        'billing',
        'signatures',
        'organization',
    ];

    public function __construct(private DocumentService $documents) {}

    /**
     * Return an in-memory, sanitized context for an Office DRAFT only.
     *
     * A null response means the template remains on the pre-existing preview
     * path. An Office draft without an eligible current workflow record gets
     * an empty context and therefore retains the existing synthetic demo
     * values; no transaction is created or changed to make a preview work.
     *
     * @return array{schema_version:int,form_type:string,source:array{kind:string},namespaces:array<string,array<string,mixed>>,available_paths:list<string>,render_values:array<string,mixed>}|null
     */
    public function forOfficeDraft(DocumentTemplate $template): ?array
    {
        if (! $this->isOfficeDraft($template)) {
            return null;
        }

        $payload = $this->documents->runtimePayloadForDraftPreview($template->document_type);

        if ($payload === null) {
            $namespaces = self::namespaces([]);

            return [
                'schema_version' => 1,
                'form_type' => (string) $template->document_type,
                'source' => ['kind' => 'SYNTHETIC_DEMO'],
                'namespaces' => $namespaces,
                'available_paths' => self::availablePaths($namespaces),
                'render_values' => [],
            ];
        }

        return self::forRuntimeData((string) $template->document_type, $payload);
    }

    /**
     * The same sanitize-into-namespaces boundary as forOfficeDraft(), but for
     * a payload the caller has already resolved itself - an already-specific
     * production record, not a best-effort preview lookup. This is what lets
     * production rendering (DocumentService) reuse the exact context-building
     * rules Draft preview already uses, without DocumentService needing to
     * depend on this service (which itself depends on DocumentService to find
     * a record to preview with) - stateless and static on purpose.
     *
     * @param array<string,mixed> $payload
     * @return array{schema_version:int,form_type:string,source:array{kind:string},namespaces:array<string,array<string,mixed>>,available_paths:list<string>,render_values:array<string,mixed>}
     */
    public static function forRuntimeData(string $type, array $payload): array
    {
        $safeValues = self::sanitizePayload($payload);
        $namespaces = self::namespaces($safeValues);

        return [
            'schema_version' => 1,
            'form_type' => $type,
            'source' => ['kind' => 'AUTHORIZED_WORKFLOW_RECORD'],
            'namespaces' => $namespaces,
            'available_paths' => self::availablePaths($namespaces),
            'render_values' => $safeValues,
        ];
    }

    /**
     * The legacy renderer continues to own preview rendering. Runtime values
     * replace matching semantic values only; omitted signatures intentionally
     * leave the existing synthetic sample signature behavior unchanged.
     *
     * @param array<string,mixed> $syntheticSample
     * @param array{render_values:array<string,mixed>} $context
     * @return array<string,mixed>
     */
    public function mergeWithSyntheticSample(array $syntheticSample, array $context): array
    {
        return [...$syntheticSample, ...$context['render_values']];
    }

    /**
     * Office Drafts resolve explicit technical paths such as
     * request.number rather than the legacy renderer's flat keys. Build that
     * same safe namespace contract for a generated sample without exposing
     * signature bytes or any model data. Authorized runtime values win over
     * synthetic values, while unknown paths remain unavailable.
     *
     * @param array{schema_version:int,form_type:string,source:array{kind:string},namespaces:array<string,array<string,mixed>>,available_paths:list<string>,render_values:array<string,mixed>} $context
     * @param array<string,mixed> $syntheticSample
     * @return array{schema_version:int,form_type:string,source:array{kind:string},namespaces:array<string,array<string,mixed>>,available_paths:list<string>,render_values:array<string,mixed>}
     */
    public function withSyntheticDemo(array $context, array $syntheticSample): array
    {
        $safeValues = [
            ...self::sanitizePayload($syntheticSample),
            ...$context['render_values'],
        ];
        $namespaces = self::namespaces($safeValues);

        return [
            ...$context,
            'namespaces' => $namespaces,
            'available_paths' => self::availablePaths($namespaces),
            'render_values' => $safeValues,
        ];
    }

    private function isOfficeDraft(DocumentTemplate $template): bool
    {
        $format = strtoupper((string) data_get($template->dynamic_schema, 'source.format', ''));

        return $template->source_mode === 'OFFICIAL_LAYOUT'
            && in_array($format, self::OFFICE_FORMATS, true)
            && ! in_array((string) $template->status, self::PROTECTED_STATES, true);
    }

    /**
     * Permit only bounded scalar document values and row data. This is an
     * allow-by-shape boundary over an already explicit document payload, not
     * a recursive model/relationship traversal.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function sanitizePayload(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;
            if ($key === '' || self::isProtectedKey($key)) {
                continue;
            }

            if ($key === 'items' && is_array($value)) {
                $rows = [];
                foreach (array_slice($value, 0, 100) as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $safeRow = self::sanitizeRow($row);
                    if ($safeRow !== []) {
                        $rows[] = $safeRow;
                    }
                }
                $safe['items'] = $rows;

                continue;
            }

            $scalar = self::safeScalar($value);
            if ($scalar !== null) {
                $safe[$key] = $scalar;
            }
        }

        return $safe;
    }

    /** @param array<string,mixed> $row @return array<string,string|int|float|bool> */
    private static function sanitizeRow(array $row): array
    {
        $safe = [];

        foreach ($row as $key => $value) {
            $key = (string) $key;
            if ($key === '' || self::isProtectedKey($key)) {
                continue;
            }

            $scalar = self::safeScalar($value);
            if ($scalar !== null) {
                $safe[$key] = $scalar;
            }
        }

        return $safe;
    }

    private static function isProtectedKey(string $key): bool
    {
        return (bool) preg_match('/(?:password|secret|token|credential|signature|snapshot|file|path|bytes|hash|email|phone|address|internal)/i', $key);
    }

    private static function safeScalar(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_substr($value, 0, 2000);
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }

    /** @param array<string,mixed> $values @return array<string,array<string,mixed>> */
    private static function namespaces(array $values): array
    {
        $namespaces = array_fill_keys(self::NAMESPACES, []);

        foreach ($values as $key => $value) {
            if ($key === 'items') {
                $namespaces['items']['records'] = $value;

                continue;
            }

            $path = self::semanticPath($key);
            if ($path === null) {
                continue;
            }

            [$namespace, $field] = explode('.', $path, 2);
            $namespaces[$namespace][$field] = $value;
        }

        return $namespaces;
    }

    /**
     * These are form-semantic names shared by the existing official document
     * payloads, not coordinates or a template-specific field catalog. Unknown
     * values stay out of the registry until the workflow explicitly exposes a
     * safe semantic meaning for them.
     */
    private static function semanticPath(string $key): ?string
    {
        return match ($key) {
            'borrower_name', 'borrowed_by_printed_name', 'requested_by_printed_name' => 'borrower.full_name',
            'borrowed_by_designation', 'requested_by_designation' => 'borrower.designation',
            'employee_checkbox' => 'borrower.is_employee',
            'others_checkbox' => 'borrower.is_other_classification',
            'other_classification' => 'borrower.classification',

            'request_no' => 'request.number',
            'purpose' => 'request.purpose',
            'requesting_office', 'office_unit' => 'request.office_unit',
            'request_location' => 'request.location',
            'request_event_details' => 'request.event_details',
            'request_division_code' => 'request.division_code',
            'request_represented_program_department' => 'request.represented_program_department',
            'request_represented_year_level' => 'request.represented_year_level',
            'request_schedule_date' => 'request.schedule_date',
            'request_return_date' => 'request.return_date',
            'request_is_off_campus' => 'request.is_off_campus',
            'destination' => 'request.destination',
            'document_date', 'date_requested' => 'request.document_date',

            'borrower_office_unit' => 'borrower.office_unit',

            'custody_no' => 'custody.number',
            'expected_return_date' => 'custody.expected_return_date',

            'approved_by', 'approved_by_printed_name' => 'approval.printed_name',
            'approved_by_designation' => 'approval.designation',
            'approved_by_date' => 'approval.date',

            'issued_by_printed_name' => 'release.printed_name',
            'issued_by_designation' => 'release.designation',
            'issued_by_date', 'date_released' => 'release.date',
            'release_time' => 'release.time',

            'date_returned' => 'return.date',
            'return_received_by_printed_name' => 'return.received_by_name',
            'return_received_by_designation' => 'return.received_by_designation',
            'return_received_by_date' => 'return.received_by_date',
            'remarks' => 'return.remarks',

            'received_by_printed_name' => 'laundry.received_by_name',
            'received_by_designation' => 'laundry.received_by_designation',
            'received_by_date' => 'laundry.received_by_date',
            'verified_by_printed_name' => 'laundry.verified_by_name',
            'verified_by_designation' => 'laundry.verified_by_designation',
            'verified_by_date' => 'laundry.verified_by_date',

            'billing_no' => 'billing.number',
            'issued_date' => 'billing.issued_date',
            'due_date' => 'billing.due_date',
            'total_amount' => 'billing.total_amount',
            'statement_remarks' => 'billing.remarks',

            'incident_no' => 'accountability.incident_number',
            'rslddp_reference' => 'accountability.rslddp_reference',
            'incident_type' => 'accountability.incident_type',
            'reported_date' => 'accountability.reported_date',
            'incident_remarks' => 'accountability.remarks',
            'appraisal_amount' => 'accountability.appraisal_amount',
            default => null,
        };
    }

    /** @param array<string,array<string,mixed>> $namespaces @return list<string> */
    private static function availablePaths(array $namespaces): array
    {
        $paths = [];
        foreach ($namespaces as $namespace => $values) {
            foreach (array_keys($values) as $field) {
                $paths[] = $namespace.'.'.$field;
            }
        }

        sort($paths);

        return $paths;
    }
}

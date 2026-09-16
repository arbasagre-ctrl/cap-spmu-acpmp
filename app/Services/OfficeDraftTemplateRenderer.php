<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Draft-only compiler for the additive Office-template path.
 *
 * It never writes back to the immutable uploaded source. Instead, it applies
 * only explicit, high-confidence Office identities to a temporary clone,
 * renders that clone with LibreOffice, and returns the resulting PDF bytes.
 * Active templates deliberately continue to use DocumentTemplateRenderer.
 */
class OfficeDraftTemplateRenderer
{
    private const DOCX_NAMESPACE = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XLSX_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RELATIONSHIPS_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    /** @var list<string> */
    private const OFFICE_FORMATS = ['DOCX', 'XLSX'];

    /** @var list<string> */
    private const PROTECTED_STATES = ['ACTIVE', 'HISTORICAL', 'FINALIZED'];

    public function __construct(private ProtectedFileService $files) {}

    /**
     * Whether this template's own uploaded source is an Office (DOCX/XLSX)
     * layout, regardless of Draft, Active, or Historical status. This is the
     * only thing compile()/render() care about: the exact same compiler runs
     * either way, which is what keeps a Draft's preview and the Active
     * layout's production output byte-identical once activated.
     */
    public function isOfficeFormat(DocumentTemplate $template): bool
    {
        $format = strtoupper((string) data_get($template->dynamic_schema, 'source.format', ''));

        return $template->source_mode === 'OFFICIAL_LAYOUT' && in_array($format, self::OFFICE_FORMATS, true);
    }

    /**
     * Whether this template is still an editable, Draft-only Office layout -
     * eligible for minor presentation editing and the Draft preparation/
     * preview flow, but never Active, Historical, or Finalized.
     */
    public function isOfficeDraft(DocumentTemplate $template): bool
    {
        return $this->isOfficeFormat($template)
            && ! in_array((string) $template->status, self::PROTECTED_STATES, true);
    }

    /**
     * Compile the exact same Draft-only path used by the generated sample,
     * then return non-content diagnostics suitable for dynamic_schema.
     *
     * @param array<string,mixed> $context
     * @return array{resolver:array<string,mixed>,compatibility_warnings:list<array<string,mixed>>,page_count:int}
     */
    public function prepare(DocumentTemplate $template, array $context): array
    {
        $compiled = $this->compile($template, $context);

        return [
            'resolver' => $compiled['plan']['resolver'],
            'compatibility_warnings' => $compiled['plan']['compatibility_warnings'],
            'page_count' => $compiled['page_count'],
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    public function render(DocumentTemplate $template, array $context): string
    {
        return $this->compile($template, $context)['pdf'];
    }

    /**
     * Builds a working Office clone without converting it. This narrow public
     * seam makes the non-destructive OOXML compiler testable without invoking
     * LibreOffice in a PHPUnit process.
     *
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $context
     * @return array{bytes:string,plan:array<string,mixed>}
     */
    public function compileWorkingSource(string $sourceBytes, array $schema, array $context): array
    {
        $plan = $this->planForSchema($schema, $context);
        $this->assertPlanIsSafe($plan);

        return [
            'bytes' => $this->applyPlanToWorkingSource($sourceBytes, $plan, $context, $schema),
            'plan' => $plan,
        ];
    }

    /**
     * Phase 4: safe, non-technical presentation text an Admin may edit on a
     * Draft - static wording that sits outside every data-bound Office
     * identity, signature region, and locked/unsupported element. Never
     * exposes a raw tag, part path, or coordinate; only a stable opaque key,
     * a human role/position label built from the wording itself, the current
     * text, and the current alignment.
     *
     * @return list<array{key:string,label:string,text:string,alignment:string}>
     */
    public function editableSections(DocumentTemplate $template): array
    {
        $this->assertMinorEditable($template);

        return $this->editableSectionsForSource($this->files->bytes($template->file), $template->dynamic_schema);
    }

    /**
     * Testable seam for editableSections() that needs no DocumentTemplate or
     * database record - the scan depends only on the immutable source bytes
     * and the already-computed schema.
     *
     * @param array<string,mixed> $schema
     * @return list<array{key:string,label:string,text:string,alignment:string}>
     */
    public function editableSectionsForSource(string $sourceBytes, array $schema): array
    {
        $format = strtoupper((string) data_get($schema, 'source.format', ''));
        if (! in_array($format, self::OFFICE_FORMATS, true)) {
            throw ValidationException::withMessages(['template' => 'The Draft has no supported Office source format.']);
        }
        $saved = $this->keyedMinorEdits($schema['minor_edits'] ?? []);

        return $this->withReadOnlyZip(
            $sourceBytes,
            fn (ZipArchive $zip): array => $format === 'DOCX'
                ? $this->docxEditableSections($zip, $schema, $saved)
                : $this->xlsxEditableSections($zip, $schema, $saved),
        );
    }

    /**
     * Validates Admin-submitted minor edits against a fresh scan of the
     * immutable source, so a submission can never touch anything outside the
     * safe editable set - not a content control, not a signature, not a
     * locked or unsupported element, and never an arbitrary XML target.
     *
     * @param list<array<string,mixed>> $submitted
     * @return list<array{key:string,text:string,alignment:?string}>
     */
    public function validateMinorEdits(DocumentTemplate $template, array $submitted): array
    {
        $this->assertMinorEditable($template);

        return $this->validateMinorEditsForSource($this->files->bytes($template->file), $template->dynamic_schema, $submitted);
    }

    /**
     * Testable seam for validateMinorEdits() that needs no DocumentTemplate.
     *
     * @param array<string,mixed> $schema
     * @param list<array<string,mixed>> $submitted
     * @return list<array{key:string,text:string,alignment:?string}>
     */
    public function validateMinorEditsForSource(string $sourceBytes, array $schema, array $submitted): array
    {
        $validKeys = array_fill_keys(
            array_column($this->editableSectionsForSource($sourceBytes, $schema), 'key'),
            true,
        );

        $edits = [];
        foreach ($submitted as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $key = (string) ($entry['key'] ?? '');
            if ($key === '' || ! isset($validKeys[$key])) {
                throw ValidationException::withMessages([
                    'edits' => 'One of the submitted edits no longer matches a safe editable section. Reopen the editor and try again.',
                ]);
            }
            $text = trim((string) ($entry['text'] ?? ''));
            if ($text === '') {
                throw ValidationException::withMessages([
                    'edits' => 'Editable text cannot be left blank. Reopen the editor and try again.',
                ]);
            }
            $alignment = $entry['alignment'] ?? null;
            $alignment = in_array($alignment, ['left', 'center', 'right', 'justify'], true) ? $alignment : null;
            // Keyed rather than appended: a duplicate submission for the same
            // section can never produce two conflicting edits for one node.
            $edits[$key] = ['key' => $key, 'text' => mb_substr($text, 0, 500), 'alignment' => $alignment];
        }

        return array_values($edits);
    }

    private function assertMinorEditable(DocumentTemplate $template): void
    {
        if (! $this->isOfficeDraft($template) || ! $template->file || ! is_array($template->dynamic_schema)) {
            throw ValidationException::withMessages([
                'template' => 'This Office Draft is not available for minor presentation editing.',
            ]);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array{pdf:string,page_count:int,plan:array<string,mixed>}
     */
    private function compile(DocumentTemplate $template, array $context): array
    {
        // Deliberately isOfficeFormat(), not isOfficeDraft(): this same
        // compile path renders both an unactivated Draft's preview and the
        // Active layout's production output, so activation can never change
        // what the document looks like.
        if (! $this->isOfficeFormat($template) || ! $template->file || ! is_array($template->dynamic_schema)) {
            throw ValidationException::withMessages([
                'template' => 'This Office layout is not available for rendering.',
            ]);
        }

        try {
            $working = $this->compileWorkingSource(
                $this->files->bytes($template->file),
                $template->dynamic_schema,
                $context,
            );
            $pdf = $this->convertWorkingOfficeSource($working['bytes'], (string) $working['plan']['format']);

            return [
                'pdf' => $pdf,
                'page_count' => $this->assertImportablePdf($pdf),
                'plan' => $working['plan'],
            ];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::warning('Office template Draft compiler failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'operation' => 'office_draft_compile',
                'document_type' => (string) $template->document_type,
                'format' => (string) data_get($template->dynamic_schema, 'source.format', ''),
            ]);

            throw ValidationException::withMessages([
                'template' => 'This Office Draft could not be rendered safely.',
            ]);
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function planForSchema(array $schema, array $context): array
    {
        $format = strtoupper((string) data_get($schema, 'source.format', ''));
        if (! in_array($format, self::OFFICE_FORMATS, true)) {
            throw ValidationException::withMessages([
                'template' => 'The Draft has no supported Office source format.',
            ]);
        }

        $available = array_fill_keys(
            array_values(array_filter($context['available_paths'] ?? [], 'is_string')),
            true,
        );
        $warnings = array_values(array_filter($schema['compatibility_warnings'] ?? [], 'is_array'));
        $bindings = [];
        $unresolved = [];
        $confidence = [];

        if ($format === 'DOCX') {
            foreach ($schema['office_identity']['content_controls'] ?? [] as $control) {
                if (! is_array($control)) {
                    continue;
                }
                $identity = $this->docxControlPath($control);
                $this->addIdentityPlan(
                    $identity,
                    'content_control',
                    [
                        'part' => (string) ($control['part'] ?? ''),
                        'tag' => (string) ($control['tag'] ?? ''),
                        'id' => (string) ($control['id'] ?? ''),
                        'classification' => (string) ($control['classification'] ?? ''),
                    ],
                    $available,
                    $bindings,
                    $unresolved,
                    $confidence,
                    $warnings,
                );
            }
        } else {
            foreach ($schema['office_identity']['named_ranges'] ?? [] as $range) {
                if (! is_array($range) || str_starts_with(strtolower((string) ($range['name'] ?? '')), '_xlnm.')) {
                    continue;
                }
                $this->addIdentityPlan(
                    $this->canonicalIdentity((string) ($range['name'] ?? '')),
                    'named_range',
                    [
                        'name' => (string) ($range['name'] ?? ''),
                        'reference' => (string) ($range['reference'] ?? ''),
                    ],
                    $available,
                    $bindings,
                    $unresolved,
                    $confidence,
                    $warnings,
                );
            }
            foreach ($schema['office_identity']['tables'] ?? [] as $table) {
                if (! is_array($table)) {
                    continue;
                }
                $this->addIdentityPlan(
                    $this->canonicalIdentity((string) ($table['name'] ?? '')),
                    'table',
                    [
                        'part' => (string) ($table['part'] ?? ''),
                        'name' => (string) ($table['name'] ?? ''),
                        'reference' => (string) ($table['ref'] ?? ''),
                        'columns' => array_values(array_filter($table['columns'] ?? [], 'is_string')),
                    ],
                    $available,
                    $bindings,
                    $unresolved,
                    $confidence,
                    $warnings,
                );
            }
        }

        if ($bindings === []) {
            $warnings[] = $this->warning(
                'WARNING',
                'NO_HIGH_CONFIDENCE_OFFICE_BINDINGS',
                'No explicit Office identity matches the safe runtime context. The Draft can be rendered, but no unambiguous system value will be inserted.',
            );
        }

        return [
            'format' => $format,
            'bindings' => $bindings,
            'resolver' => [
                'bindings' => $bindings,
                'unresolved' => $unresolved,
                'confidence' => $confidence,
            ],
            'compatibility_warnings' => $this->uniqueWarnings($warnings),
        ];
    }

    /**
     * @param array<string,bool> $available
     * @param list<array<string,mixed>> $bindings
     * @param list<array<string,mixed>> $unresolved
     * @param list<array<string,mixed>> $confidence
     * @param list<array<string,mixed>> $warnings
     * @param array<string,mixed> $target
     */
    private function addIdentityPlan(?string $path, string $kind, array $target, array $available, array &$bindings, array &$unresolved, array &$confidence, array &$warnings): void
    {
        $identity = (string) ($target['tag'] ?? $target['name'] ?? '');
        if (($target['classification'] ?? '') === 'LOCKED_UNSUPPORTED') {
            $unresolved[] = ['identity' => $identity, 'reason' => 'LOCKED_UNSUPPORTED'];

            return;
        }
        if ($path === null) {
            $unresolved[] = ['identity' => $identity, 'reason' => 'UNSUPPORTED_OR_AMBIGUOUS_IDENTITY'];
            /*
             * The resolution priority (embedded Office identity, structural
             * recognition, semantic visible-label recognition) fell through
             * for this one field: the tag reads as a human label, not a
             * structured system path, so it is left untouched rather than
             * guessed at. This is the per-field instance of the same signal
             * the document-wide check below raises when nothing resolves at
             * all.
             */
            $warnings[] = $this->warning(
                'WARNING',
                'NO_HIGH_CONFIDENCE_OFFICE_BINDINGS',
                'An Office identity tag reads as a human label rather than a structured system path, so no runtime value was inserted for it.',
                ['identity' => $identity],
            );

            return;
        }
        if ($this->isSignaturePath($path)) {
            $unresolved[] = ['identity' => $identity, 'path' => $path, 'reason' => 'SIGNATURES_RETAIN_EXISTING_SYSTEM'];
            $warnings[] = $this->warning(
                'INFO',
                'SIGNATURE_REGION_DEFERRED',
                'A signature region was recognized but remains on the existing signature system during the Draft-only Office phase.',
                ['identity' => $identity],
            );

            return;
        }
        if (! isset($available[$path])) {
            $unresolved[] = ['identity' => $identity, 'path' => $path, 'reason' => 'RUNTIME_VALUE_NOT_AVAILABLE'];
            $warnings[] = $this->warning(
                'WARNING',
                'OFFICE_IDENTITY_RUNTIME_VALUE_UNAVAILABLE',
                'An explicit Office identity has no value in the sanitized runtime context and was left unchanged.',
                ['identity' => $identity, 'path' => $path],
            );

            return;
        }

        $binding = ['kind' => $kind, 'identity' => $identity, 'path' => $path, ...$target];
        $bindings[] = $binding;
        $confidence[] = [
            'identity' => $identity,
            'path' => $path,
            'level' => 'HIGH',
            'reason' => 'explicit_office_identity',
        ];
    }

    /** @param array<string,mixed> $plan */
    private function assertPlanIsSafe(array $plan): void
    {
        foreach ($plan['compatibility_warnings'] as $warning) {
            if (($warning['severity'] ?? null) === 'BLOCKING') {
                throw ValidationException::withMessages([
                    'template' => (string) ($warning['message'] ?? 'This Office Draft has a blocking compatibility issue.'),
                ]);
            }
        }
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $context
     * @param array<string,mixed> $schema
     */
    private function applyPlanToWorkingSource(string $sourceBytes, array $plan, array $context, array $schema): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['template' => 'Office Draft rendering requires the PHP ZIP extension.']);
        }

        $path = tempnam(sys_get_temp_dir(), 'spmu-office-draft-');
        if ($path === false || file_put_contents($path, $sourceBytes) === false) {
            throw ValidationException::withMessages(['template' => 'Could not create a temporary Office Draft clone.']);
        }

        $zip = new ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['template' => 'The immutable Office Draft source is unreadable.']);
            }
            $opened = true;
            $this->assertSafeOfficeParts($zip);

            if ($plan['format'] === 'DOCX') {
                $this->applyDocxPlan($zip, $plan, $context);
                $this->applyDocxMinorEdits($zip, $schema);
            } else {
                $this->applyXlsxPlan($zip, $plan, $context);
                $this->applyXlsxMinorEdits($zip, $schema);
            }
            $zip->close();
            $opened = false;
            $bytes = file_get_contents($path);
            if (! is_string($bytes) || $bytes === '') {
                throw ValidationException::withMessages(['template' => 'The Office Draft clone could not be finalized safely.']);
            }

            return $bytes;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context */
    private function applyDocxPlan(ZipArchive $zip, array $plan, array $context): void
    {
        $byPart = [];
        foreach ($plan['bindings'] as $binding) {
            if (($binding['kind'] ?? '') !== 'content_control' || ($binding['part'] ?? '') === '') {
                continue;
            }
            $byPart[$binding['part']][] = $binding;
        }

        foreach ($byPart as $part => $bindings) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                $this->block('A resolved DOCX region is missing from the immutable source.');
            }
            [$document, $xpath] = $this->docxXml($xml, $part);

            foreach ($bindings as $binding) {
                if (($binding['path'] ?? '') === 'items.records') {
                    $this->expandDocxRepeatRegion($document, $xpath, $binding, $context);
                }
            }
            foreach ($bindings as $binding) {
                $path = (string) ($binding['path'] ?? '');
                if ($path === 'items.records' || str_starts_with($path, 'items.')) {
                    continue;
                }
                $value = $this->contextValue($context, $path, $found);
                if (! $found || ! $this->isScalarValue($value)) {
                    continue;
                }
                foreach ($this->matchingDocxControls($xpath, $binding) as $control) {
                    $this->writeDocxControlText($document, $xpath, $control, $this->stringValue($value));
                }
            }

            $zip->addFromString($part, $document->saveXML());
        }
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $context */
    private function expandDocxRepeatRegion(DOMDocument $document, DOMXPath $xpath, array $binding, array $context): void
    {
        $records = $this->contextValue($context, 'items.records', $found);
        if (! $found || ! is_array($records)) {
            return;
        }
        foreach ($this->matchingDocxControls($xpath, $binding) as $region) {
            $content = $xpath->query('./w:sdtContent', $region)->item(0);
            if (! $content instanceof DOMElement) {
                $this->block('The resolved repeating DOCX region has no writable content.');
            }
            $rows = $xpath->query('./w:tr', $content);
            if ($rows->length !== 1 || ! $rows->item(0) instanceof DOMElement) {
                $this->block('The resolved repeating DOCX region is not a single safe table-row template.');
            }
            $templateRow = $rows->item(0);
            if ($this->docxItemControlCount($xpath, $templateRow) === 0) {
                $this->block('The resolved repeating DOCX row has no explicit item field identities.');
            }

            while ($content->firstChild) {
                $content->removeChild($content->firstChild);
            }
            foreach ($records as $record) {
                if (! is_array($record)) {
                    continue;
                }
                $row = $templateRow->cloneNode(true);
                if (! $row instanceof DOMElement) {
                    $this->block('The resolved repeating DOCX row could not be cloned safely.');
                }
                $this->relaxDocxRowHeight($xpath, $row);
                $this->writeDocxItemRow($document, $xpath, $row, $record);
                $content->appendChild($row);
            }
        }
    }

    private function docxItemControlCount(DOMXPath $xpath, DOMElement $row): int
    {
        $count = 0;
        foreach ($xpath->query('.//w:sdt', $row) as $control) {
            if ($control instanceof DOMElement && str_starts_with((string) $this->docxControlPath($this->docxControlMetadata($xpath, $control)), 'items.')) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array<string,mixed> $record */
    private function writeDocxItemRow(DOMDocument $document, DOMXPath $xpath, DOMElement $row, array $record): void
    {
        foreach ($xpath->query('.//w:sdt', $row) as $control) {
            if (! $control instanceof DOMElement) {
                continue;
            }
            $path = $this->docxControlPath($this->docxControlMetadata($xpath, $control));
            if ($path === null || ! str_starts_with($path, 'items.') || $path === 'items.records') {
                continue;
            }
            $key = substr($path, strlen('items.'));
            $value = $record[$key] ?? '';
            if ($this->isScalarValue($value)) {
                $this->writeDocxControlText($document, $xpath, $control, $this->stringValue($value));
            }
        }
    }

    private function relaxDocxRowHeight(DOMXPath $xpath, DOMElement $row): void
    {
        foreach ($xpath->query('./w:trPr/w:trHeight', $row) as $height) {
            if ($height instanceof DOMElement && $height->getAttributeNS(self::DOCX_NAMESPACE, 'hRule') === 'exact') {
                $height->setAttributeNS(self::DOCX_NAMESPACE, 'w:hRule', 'atLeast');
            }
        }
    }

    /** @param array<string,mixed> $binding @return list<DOMElement> */
    private function matchingDocxControls(DOMXPath $xpath, array $binding): array
    {
        $matches = [];
        foreach ($xpath->query('//w:sdt') as $control) {
            if (! $control instanceof DOMElement) {
                continue;
            }
            $meta = $this->docxControlMetadata($xpath, $control);
            if (($binding['tag'] ?? '') !== '' && ($meta['tag'] ?? '') === $binding['tag']) {
                $matches[] = $control;
                continue;
            }
            if (($binding['tag'] ?? '') === '' && ($binding['id'] ?? '') !== '' && ($meta['id'] ?? '') === $binding['id']) {
                $matches[] = $control;
            }
        }

        return $matches;
    }

    private function writeDocxControlText(DOMDocument $document, DOMXPath $xpath, DOMElement $control, string $value): void
    {
        if ($xpath->query('ancestor::w:txbxContent', $control)->length > 0) {
            $this->block('A resolved DOCX field is inside a floating text box and cannot be populated safely.');
        }
        $content = $xpath->query('./w:sdtContent', $control)->item(0);
        if (! $content instanceof DOMElement || $xpath->query('.//w:p', $content)->length > 1) {
            $this->block('A resolved DOCX field is not a single flow-text region and cannot be populated safely.');
        }
        $texts = $xpath->query('.//w:t', $content);
        if ($texts->length === 0) {
            $paragraph = $xpath->query('.//w:p', $content)->item(0);
            if (! $paragraph instanceof DOMElement) {
                $this->block('A resolved DOCX field has no writable text run.');
            }
            $run = $document->createElementNS(self::DOCX_NAMESPACE, 'w:r');
            $text = $document->createElementNS(self::DOCX_NAMESPACE, 'w:t');
            $run->appendChild($text);
            $paragraph->appendChild($run);
            $texts = $xpath->query('.//w:t', $content);
        }
        $first = $texts->item(0);
        if (! $first instanceof DOMElement) {
            $this->block('A resolved DOCX field has no writable text run.');
        }
        $first->nodeValue = $value;
        if ($value !== trim($value)) {
            $first->setAttributeNS(self::XML_NAMESPACE, 'xml:space', 'preserve');
        }
        for ($index = $texts->length - 1; $index >= 1; $index--) {
            $text = $texts->item($index);
            if ($text?->parentNode) {
                $text->parentNode->removeChild($text);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Phase 4: minor presentation edits (DOCX)                            */
    /* ------------------------------------------------------------------ */

    /** @param array<string,mixed> $schema */
    private function applyDocxMinorEdits(ZipArchive $zip, array $schema): void
    {
        $saved = $this->keyedMinorEdits($schema['minor_edits'] ?? []);
        if ($saved === []) {
            return;
        }
        $changedParts = [];
        foreach ($this->docxEditableNodeSequence($zip, $schema) as $index => $node) {
            $key = (string) $index;
            if (! isset($saved[$key])) {
                continue;
            }
            $this->writeDocxParagraphText($node['document'], $node['xpath'], $node['paragraph'], $saved[$key]['text'], $saved[$key]['alignment']);
            $changedParts[$node['part']] = $node['document'];
        }
        foreach ($changedParts as $part => $document) {
            $zip->addFromString($part, $document->saveXML());
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array{text:string,alignment:?string}> $saved
     * @return list<array{key:string,label:string,text:string,alignment:string}>
     */
    private function docxEditableSections(ZipArchive $zip, array $schema, array $saved): array
    {
        $sections = [];
        foreach ($this->docxEditableNodeSequence($zip, $schema) as $index => $node) {
            $key = (string) $index;
            $text = $this->docxParagraphText($node['paragraph']);
            $override = $saved[$key] ?? null;
            $currentText = $override['text'] ?? $text;
            $sections[] = [
                'key' => $key,
                'label' => $this->presentationLabel($node['role'], $index + 1, $currentText),
                'text' => $currentText,
                'alignment' => $override['alignment'] ?? $this->docxParagraphAlignment($node['xpath'], $node['paragraph']),
            ];
        }

        return $sections;
    }

    /**
     * One consistent, deterministic sequence over every safe editable DOCX
     * paragraph - never one that belongs to a content control (data-bound,
     * ambiguous, or explicitly locked) and never one inside a floating text
     * box, which the schema already reports as unsupported. Listing the
     * sections and applying a saved edit both walk this exact sequence, so a
     * plain position index (never a tag, part path, or coordinate) always
     * addresses the same paragraph.
     *
     * @param array<string,mixed> $schema
     * @return list<array{part:string,role:string,paragraph:DOMElement,document:DOMDocument,xpath:DOMXPath}>
     */
    private function docxEditableNodeSequence(ZipArchive $zip, array $schema): array
    {
        $sequence = [];
        foreach ($this->docxEditablePartRoles($schema) as $part => $role) {
            $xml = $zip->getFromName($part);
            if (! is_string($xml)) {
                continue;
            }
            [$document, $xpath] = $this->docxXml($xml, $part);
            foreach ($xpath->query('//w:p') as $paragraph) {
                if (! $paragraph instanceof DOMElement) {
                    continue;
                }
                if ($xpath->query('ancestor::w:sdt', $paragraph)->length > 0) {
                    continue;
                }
                if ($xpath->query('ancestor::w:txbxContent', $paragraph)->length > 0) {
                    continue;
                }
                if (trim($this->docxParagraphText($paragraph)) === '') {
                    continue;
                }
                $sequence[] = ['part' => $part, 'role' => $role, 'paragraph' => $paragraph, 'document' => $document, 'xpath' => $xpath];
            }
        }

        return $sequence;
    }

    /**
     * Every DOCX part this Draft may show static text from, and the
     * non-technical role it plays: the main body, or a header/footer.
     *
     * @param array<string,mixed> $schema
     * @return array<string,string>
     */
    private function docxEditablePartRoles(array $schema): array
    {
        $parts = ['word/document.xml' => 'body'];
        foreach ($schema['document_structure']['headers_footers'] ?? [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['part'] ?? null) || $entry['part'] === '') {
                continue;
            }
            $parts[$entry['part']] = str_contains($entry['part'], 'header') ? 'header' : 'footer';
        }

        return $parts;
    }

    private function docxParagraphText(DOMElement $paragraph): string
    {
        $text = '';
        foreach ($paragraph->getElementsByTagNameNS(self::DOCX_NAMESPACE, 't') as $run) {
            $text .= $run->nodeValue;
        }

        return $text;
    }

    private function docxParagraphAlignment(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $jc = $xpath->query('./w:pPr/w:jc', $paragraph)->item(0);
        $value = $jc instanceof DOMElement ? $jc->getAttributeNS(self::DOCX_NAMESPACE, 'val') : '';

        return match ($value) {
            'center' => 'center',
            'right', 'end' => 'right',
            'both', 'distribute' => 'justify',
            default => 'left',
        };
    }

    /**
     * Rewrites a static paragraph's wording exactly like a content control's
     * text (one run carries the value, the rest are dropped), so a label with
     * mixed run formatting degrades the same safe way a bound field already
     * does. Alignment, when given, is written as the paragraph's own w:jc.
     */
    private function writeDocxParagraphText(DOMDocument $document, DOMXPath $xpath, DOMElement $paragraph, string $text, ?string $alignment): void
    {
        $texts = $xpath->query('.//w:t', $paragraph);
        if ($texts->length === 0) {
            return;
        }
        $first = $texts->item(0);
        if (! $first instanceof DOMElement) {
            return;
        }
        $first->nodeValue = $text;
        if ($text !== trim($text)) {
            $first->setAttributeNS(self::XML_NAMESPACE, 'xml:space', 'preserve');
        } else {
            $first->removeAttributeNS(self::XML_NAMESPACE, 'space');
        }
        for ($index = $texts->length - 1; $index >= 1; $index--) {
            $node = $texts->item($index);
            if ($node?->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        if ($alignment === null) {
            return;
        }
        $value = match ($alignment) {
            'center' => 'center',
            'right' => 'end',
            'justify' => 'both',
            default => 'left',
        };
        $paragraphProperties = $xpath->query('./w:pPr', $paragraph)->item(0);
        if (! $paragraphProperties instanceof DOMElement) {
            $paragraphProperties = $document->createElementNS(self::DOCX_NAMESPACE, 'w:pPr');
            $paragraph->insertBefore($paragraphProperties, $paragraph->firstChild);
        }
        $jc = $xpath->query('./w:jc', $paragraphProperties)->item(0);
        if (! $jc instanceof DOMElement) {
            $jc = $document->createElementNS(self::DOCX_NAMESPACE, 'w:jc');
            $paragraphProperties->appendChild($jc);
        }
        $jc->setAttributeNS(self::DOCX_NAMESPACE, 'w:val', $value);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $context */
    private function applyXlsxPlan(ZipArchive $zip, array $plan, array $context): void
    {
        $stylesXml = $zip->getFromName('xl/styles.xml');
        if (! is_string($stylesXml)) {
            $this->block('The XLSX Draft has no style metadata required for safe wrapped text rendering.');
        }
        [$styles, $stylesXpath] = $this->xlsxXml($stylesXml, 'xl/styles.xml');
        $styleCache = [];
        $sheetDocuments = [];

        foreach ($plan['bindings'] as $binding) {
            if (($binding['kind'] ?? '') !== 'named_range') {
                continue;
            }
            $value = $this->contextValue($context, (string) $binding['path'], $found);
            if (! $found || ! $this->isScalarValue($value)) {
                continue;
            }
            $reference = $this->xlsxReference((string) ($binding['reference'] ?? ''));
            if ($reference === null) {
                $this->block('A resolved XLSX named range is not a safe worksheet cell reference.');
            }
            [$sheetName, $start, $end] = $reference;
            [$document, $xpath] = $this->xlsxSheetDocument($zip, $plan, $sheetDocuments, $sheetName);
            if ($end !== null && $end !== $start && ! $this->isExactMergedRange($xpath, $start, $end)) {
                $this->block('A resolved XLSX named range spans multiple unmerged cells and cannot be populated safely.');
            }
            $cell = $this->xlsxCell($xpath, $start);
            if (! $cell instanceof DOMElement) {
                $this->block('A resolved XLSX named range has no styled source cell to preserve.');
            }
            $this->writeXlsxCellText($document, $xpath, $styles, $stylesXpath, $styleCache, $cell, $this->stringValue($value));
        }

        foreach ($plan['bindings'] as $binding) {
            if (($binding['kind'] ?? '') !== 'table' || ($binding['path'] ?? '') !== 'items.records') {
                continue;
            }
            $this->populateXlsxItemTable($zip, $plan, $context, $binding, $styles, $stylesXpath, $styleCache, $sheetDocuments);
        }

        foreach ($sheetDocuments as $part => $sheet) {
            $zip->addFromString($part, $sheet['document']->saveXML());
        }
        $zip->addFromString('xl/styles.xml', $styles->saveXML());
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,array{document:DOMDocument,xpath:DOMXPath}> $documents
     * @return array{0:DOMDocument,1:DOMXPath}
     */
    private function xlsxSheetDocument(ZipArchive $zip, array $plan, array &$documents, string $sheetName): array
    {
        foreach ($plan['document_structure']['worksheets'] ?? [] as $worksheet) {
            // The plan does not retain the whole schema structure. This
            // fallback is intentionally unused; worksheet lookup happens from
            // the source package below when a named range is rendered.
        }
        foreach ($documents as $part => $entry) {
            if (($entry['name'] ?? null) === $sheetName) {
                return [$entry['document'], $entry['xpath']];
            }
        }
        $part = $this->xlsxWorksheetPartForName($zip, $sheetName);
        if ($part === null || ! is_string($xml = $zip->getFromName($part))) {
            $this->block('A resolved XLSX named range references a missing worksheet.');
        }
        [$document, $xpath] = $this->xlsxXml($xml, $part);
        $documents[$part] = ['name' => $sheetName, 'document' => $document, 'xpath' => $xpath];

        return [$document, $xpath];
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $context
     * @param array<string,mixed> $binding
     * @param array<string,int> $styleCache
     * @param array<string,array{document:DOMDocument,xpath:DOMXPath}> $sheetDocuments
     */
    private function populateXlsxItemTable(ZipArchive $zip, array $plan, array $context, array $binding, DOMDocument $styles, DOMXPath $stylesXpath, array &$styleCache, array &$sheetDocuments): void
    {
        $records = $this->contextValue($context, 'items.records', $found);
        if (! $found || ! is_array($records)) {
            return;
        }
        $tablePart = (string) ($binding['part'] ?? '');
        $tableXml = $zip->getFromName($tablePart);
        if ($tablePart === '' || ! is_string($tableXml)) {
            $this->block('The resolved XLSX item table is missing from the immutable source.');
        }
        [, $tableXpath] = $this->xlsxXml($tableXml, $tablePart);
        $table = $tableXpath->query('//x:table')->item(0);
        if (! $table instanceof DOMElement) {
            $this->block('The resolved XLSX item table is unreadable.');
        }
        $range = $this->xlsxTableRange($table->getAttribute('ref'));
        if ($range === null) {
            $this->block('The resolved XLSX item table has an unsupported layout range.');
        }
        [$firstColumn, $firstRow, $lastColumn, $lastRow] = $range;
        $capacity = $lastRow - $firstRow;
        if (count($records) > $capacity) {
            $this->block('This document contains more item records than the approved Office layout can display safely.');
        }
        $columnKeys = [];
        foreach ($tableXpath->query('//x:tableColumns/x:tableColumn') as $index => $column) {
            if (! $column instanceof DOMElement) {
                continue;
            }
            $identity = $this->canonicalIdentity($column->getAttribute('name'));
            $key = $identity !== null && str_starts_with($identity, 'items.')
                ? substr($identity, strlen('items.'))
                : $this->safeItemColumnKey($column->getAttribute('name'));
            if ($key !== null) {
                $columnKeys[$index] = $key;
            }
        }
        if ($records !== [] && $columnKeys === []) {
            $this->block('The resolved XLSX item table has no explicit item columns that can be populated safely.');
        }
        $sheetPart = $this->xlsxWorksheetPartForTable($zip, $tablePart);
        if ($sheetPart === null || ! is_string($sheetXml = $zip->getFromName($sheetPart))) {
            $this->block('The resolved XLSX item table is not connected to a worksheet.');
        }
        if (! isset($sheetDocuments[$sheetPart])) {
            [$document, $xpath] = $this->xlsxXml($sheetXml, $sheetPart);
            $sheetDocuments[$sheetPart] = ['name' => '', 'document' => $document, 'xpath' => $xpath];
        }
        $document = $sheetDocuments[$sheetPart]['document'];
        $xpath = $sheetDocuments[$sheetPart]['xpath'];

        foreach (range(1, $capacity) as $offset) {
            $rowNumber = $firstRow + $offset;
            $row = $xpath->query('//x:sheetData/x:row[@r="'.$rowNumber.'"]')->item(0);
            if (! $row instanceof DOMElement) {
                $this->block('The resolved XLSX item table has no safe preallocated row for dynamic records.');
            }
            $this->relaxXlsxRowHeight($row);
            $record = $records[$offset - 1] ?? [];
            if (! is_array($record)) {
                $record = [];
            }
            foreach ($columnKeys as $columnOffset => $key) {
                $cellReference = $this->xlsxColumnName($firstColumn + $columnOffset).$rowNumber;
                $cell = $this->xlsxCell($xpath, $cellReference);
                if (! $cell instanceof DOMElement) {
                    $this->block('The resolved XLSX item table has no styled source cell to preserve.');
                }
                $this->writeXlsxCellText(
                    $document,
                    $xpath,
                    $styles,
                    $stylesXpath,
                    $styleCache,
                    $cell,
                    $this->isScalarValue($record[$key] ?? null) ? $this->stringValue($record[$key]) : '',
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Phase 4: minor presentation edits (XLSX)                            */
    /* ------------------------------------------------------------------ */

    /** @param array<string,mixed> $schema */
    private function applyXlsxMinorEdits(ZipArchive $zip, array $schema): void
    {
        $saved = $this->keyedMinorEdits($schema['minor_edits'] ?? []);
        if ($saved === []) {
            return;
        }
        $stylesXml = $zip->getFromName('xl/styles.xml');
        if (! is_string($stylesXml)) {
            return;
        }
        [$styles, $stylesXpath] = $this->xlsxXml($stylesXml, 'xl/styles.xml');
        $styleCache = [];
        $stylesChanged = false;
        $changedParts = [];

        foreach ($this->xlsxEditableNodeSequence($zip, $schema) as $index => $node) {
            $key = (string) $index;
            if (! isset($saved[$key])) {
                continue;
            }
            $this->writeXlsxCellText($node['document'], $node['xpath'], $styles, $stylesXpath, $styleCache, $node['cell'], $saved[$key]['text']);
            if ($saved[$key]['alignment'] !== null) {
                $this->applyXlsxCellAlignment($styles, $stylesXpath, $node['cell'], $saved[$key]['alignment']);
            }
            $changedParts[$node['part']] = $node['document'];
            $stylesChanged = true;
        }
        foreach ($changedParts as $part => $document) {
            $zip->addFromString($part, $document->saveXML());
        }
        if ($stylesChanged) {
            $zip->addFromString('xl/styles.xml', $styles->saveXML());
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array{text:string,alignment:?string}> $saved
     * @return list<array{key:string,label:string,text:string,alignment:string}>
     */
    private function xlsxEditableSections(ZipArchive $zip, array $schema, array $saved): array
    {
        $stylesXpath = null;
        $stylesXml = $zip->getFromName('xl/styles.xml');
        if (is_string($stylesXml)) {
            [, $stylesXpath] = $this->xlsxXml($stylesXml, 'xl/styles.xml');
        }

        $sections = [];
        foreach ($this->xlsxEditableNodeSequence($zip, $schema) as $index => $node) {
            $key = (string) $index;
            $override = $saved[$key] ?? null;
            $currentText = $override['text'] ?? $node['text'];
            $sections[] = [
                'key' => $key,
                'label' => $this->presentationLabel('worksheet', $index + 1, $currentText),
                'text' => $currentText,
                'alignment' => $override['alignment'] ?? ($stylesXpath ? $this->xlsxCellAlignment($stylesXpath, $node['cell']) : 'left'),
            ];
        }

        return $sections;
    }

    /**
     * One consistent, deterministic sequence over every safe editable XLSX
     * cell - never the anchor cell of a named range, never a cell inside a
     * table's declared range, and never a formula cell - so a minor edit can
     * never collide with a data binding. Listing the sections and applying a
     * saved edit both walk this exact sequence, so a plain position index
     * (never a cell reference) always addresses the same cell.
     *
     * @param array<string,mixed> $schema
     * @return list<array{part:string,cell:DOMElement,document:DOMDocument,xpath:DOMXPath,text:string}>
     */
    private function xlsxEditableNodeSequence(ZipArchive $zip, array $schema): array
    {
        $excluded = $this->xlsxExcludedCellKeys($zip, $schema);
        $sharedStrings = $this->xlsxSharedStrings($zip);
        $sequence = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $part = $zip->getNameIndex($index);
            if (! is_string($part) || preg_match('#^xl/worksheets/[^/]+\.xml$#', $part) !== 1) {
                continue;
            }
            $xml = $zip->getFromIndex($index);
            if (! is_string($xml)) {
                continue;
            }
            [$document, $xpath] = $this->xlsxXml($xml, $part);
            foreach ($xpath->query('//x:sheetData/x:row/x:c') as $cell) {
                if (! $cell instanceof DOMElement) {
                    continue;
                }
                $reference = $cell->getAttribute('r');
                if ($reference === '' || isset($excluded[$part.'#'.$reference])) {
                    continue;
                }
                if ($xpath->query('./x:f', $cell)->length > 0) {
                    continue;
                }
                $text = $this->xlsxCellText($xpath, $cell, $sharedStrings);
                if (trim($text) === '') {
                    continue;
                }
                $sequence[] = ['part' => $part, 'cell' => $cell, 'document' => $document, 'xpath' => $xpath, 'text' => $text];
            }
        }

        return $sequence;
    }

    /**
     * Cells that already carry an explicit Office identity - the anchor cell
     * of every named range, and every cell inside a table's declared range -
     * are never offered as static presentation text, so a minor edit can
     * never collide with a data binding.
     *
     * @param array<string,mixed> $schema
     * @return array<string,bool>
     */
    private function xlsxExcludedCellKeys(ZipArchive $zip, array $schema): array
    {
        $excluded = [];
        foreach ($schema['office_identity']['named_ranges'] ?? [] as $range) {
            if (! is_array($range)) {
                continue;
            }
            $reference = $this->xlsxReference((string) ($range['reference'] ?? ''));
            if ($reference === null) {
                continue;
            }
            [$sheetName, $start] = $reference;
            $part = $this->xlsxWorksheetPartForName($zip, $sheetName);
            if ($part !== null) {
                $excluded[$part.'#'.$start] = true;
            }
        }
        foreach ($schema['office_identity']['tables'] ?? [] as $table) {
            if (! is_array($table)) {
                continue;
            }
            $tablePart = (string) ($table['part'] ?? '');
            $sheetPart = $tablePart !== '' ? $this->xlsxWorksheetPartForTable($zip, $tablePart) : null;
            $range = $this->xlsxTableRange((string) ($table['ref'] ?? ''));
            if ($sheetPart === null || $range === null) {
                continue;
            }
            [$firstColumn, $firstRow, $lastColumn, $lastRow] = $range;
            for ($row = $firstRow; $row <= $lastRow; $row++) {
                for ($column = $firstColumn; $column <= $lastColumn; $column++) {
                    $excluded[$sheetPart.'#'.$this->xlsxColumnName($column).$row] = true;
                }
            }
        }

        return $excluded;
    }

    /** @return list<string> */
    private function xlsxSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml)) {
            return [];
        }
        [, $xpath] = $this->xlsxXml($xml, 'xl/sharedStrings.xml');
        $strings = [];
        foreach ($xpath->query('//x:sst/x:si') as $entry) {
            if (! $entry instanceof DOMElement) {
                continue;
            }
            $text = '';
            foreach ($xpath->query('.//x:t', $entry) as $run) {
                $text .= $run->nodeValue;
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /** @param list<string> $sharedStrings */
    private function xlsxCellText(DOMXPath $xpath, DOMElement $cell, array $sharedStrings): string
    {
        $type = $cell->getAttribute('t');
        if ($type === 'inlineStr') {
            $text = '';
            foreach ($xpath->query('./x:is//x:t', $cell) as $run) {
                $text .= $run->nodeValue;
            }

            return $text;
        }
        if ($type === 's') {
            $value = $xpath->query('./x:v', $cell)->item(0);
            $sharedIndex = $value instanceof DOMElement ? (int) $value->nodeValue : null;

            return $sharedIndex !== null && isset($sharedStrings[$sharedIndex]) ? $sharedStrings[$sharedIndex] : '';
        }
        if ($type === 'str' || $type === '') {
            $value = $xpath->query('./x:v', $cell)->item(0);

            return $value instanceof DOMElement ? (string) $value->nodeValue : '';
        }

        return '';
    }

    private function xlsxCellAlignment(DOMXPath $stylesXpath, DOMElement $cell): string
    {
        $styleAttribute = $cell->getAttribute('s');
        if ($styleAttribute === '') {
            return 'left';
        }
        $xf = $stylesXpath->query('//x:cellXfs/x:xf')->item((int) $styleAttribute);
        if (! $xf instanceof DOMElement) {
            return 'left';
        }
        $alignment = $stylesXpath->query('./x:alignment', $xf)->item(0);
        $value = $alignment instanceof DOMElement ? $alignment->getAttribute('horizontal') : '';

        return match ($value) {
            'center', 'centerContinuous' => 'center',
            'right' => 'right',
            'justify', 'distributed' => 'justify',
            default => 'left',
        };
    }

    /**
     * Mutates the alignment of the cell style writeXlsxCellText() already
     * assigned - the wrapped style it just created or reused - rather than
     * allocating yet another style entry for the same cell.
     */
    private function applyXlsxCellAlignment(DOMDocument $styles, DOMXPath $stylesXpath, DOMElement $cell, string $alignment): void
    {
        $index = (int) $cell->getAttribute('s');
        $xf = $stylesXpath->query('//x:cellXfs/x:xf')->item($index);
        if (! $xf instanceof DOMElement) {
            return;
        }
        $alignmentNode = $stylesXpath->query('./x:alignment', $xf)->item(0);
        if (! $alignmentNode instanceof DOMElement) {
            $alignmentNode = $styles->createElementNS(self::XLSX_NAMESPACE, 'alignment');
            $xf->appendChild($alignmentNode);
        }
        $value = match ($alignment) {
            'center' => 'center',
            'right' => 'right',
            'justify' => 'justify',
            default => 'left',
        };
        $alignmentNode->setAttribute('horizontal', $value);
        $xf->setAttribute('applyAlignment', '1');
    }

    /* ------------------------------------------------------------------ */
    /* Phase 4: shared helpers                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param list<array<string,mixed>> $minorEdits
     * @return array<string,array{text:string,alignment:?string}>
     */
    private function keyedMinorEdits(array $minorEdits): array
    {
        $keyed = [];
        foreach ($minorEdits as $edit) {
            if (is_array($edit) && is_string($edit['key'] ?? null) && $edit['key'] !== '') {
                $keyed[$edit['key']] = [
                    'text' => is_string($edit['text'] ?? null) ? $edit['text'] : '',
                    'alignment' => is_string($edit['alignment'] ?? null) ? $edit['alignment'] : null,
                ];
            }
        }

        return $keyed;
    }

    /**
     * A short, self-describing label built from the wording itself - never a
     * tag, a part path, or a coordinate - so a non-technical Admin can tell
     * two editable sections apart without seeing anything technical.
     */
    private function presentationLabel(string $role, int $sequence, string $text): string
    {
        $roleLabel = match ($role) {
            'header' => 'Header text',
            'footer' => 'Footer text',
            'worksheet' => 'Worksheet text',
            default => 'Document text',
        };
        $preview = Str::of($text)->squish()->limit(40);

        return "{$roleLabel} {$sequence} — \"{$preview}\"";
    }

    /**
     * Opens the immutable source read-only for scanning - never for writing.
     * Applying an edit always goes through the same temporary-clone path as
     * every other Draft compile.
     *
     * @template T
     * @param callable(ZipArchive):T $callback
     * @return T
     */
    private function withReadOnlyZip(string $bytes, callable $callback): mixed
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['template' => 'Office Draft rendering requires the PHP ZIP extension.']);
        }
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-scan-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw ValidationException::withMessages(['template' => 'Could not read the immutable Office Draft source.']);
        }
        $zip = new ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['template' => 'The immutable Office Draft source is unreadable.']);
            }
            $opened = true;

            return $callback($zip);
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($path);
        }
    }

    /** @return array{0:DOMDocument,1:DOMXPath} */
    private function docxXml(string $xml, string $part): array
    {
        return $this->xml($xml, 'DOCX', $part, ['w' => self::DOCX_NAMESPACE]);
    }

    /** @return array{0:DOMDocument,1:DOMXPath} */
    private function xlsxXml(string $xml, string $part): array
    {
        return $this->xml($xml, 'XLSX', $part, ['x' => self::XLSX_NAMESPACE, 'r' => self::RELATIONSHIPS_NAMESPACE]);
    }

    /** @return array{0:DOMDocument,1:DOMXPath} */
    private function xml(string $xml, string $format, string $part, array $namespaces): array
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;
        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
            $this->block("The $format Draft contains unreadable XML in $part.");
        }
        $xpath = new DOMXPath($document);
        foreach ($namespaces as $prefix => $namespace) {
            $xpath->registerNamespace($prefix, $namespace);
        }

        return [$document, $xpath];
    }

    /** @return array<string,mixed> */
    private function docxControlMetadata(DOMXPath $xpath, DOMElement $control): array
    {
        $properties = $xpath->query('./w:sdtPr', $control)->item(0);
        $tag = $xpath->query('./w:tag', $properties)->item(0);
        $id = $xpath->query('./w:id', $properties)->item(0);
        $binding = $xpath->query('./w:dataBinding', $properties)->item(0);

        return [
            'tag' => $tag instanceof DOMElement ? $tag->getAttributeNS(self::DOCX_NAMESPACE, 'val') : '',
            'id' => $id instanceof DOMElement ? $id->getAttributeNS(self::DOCX_NAMESPACE, 'val') : '',
            'data_binding' => $binding instanceof DOMElement ? ['xpath' => $binding->getAttributeNS(self::DOCX_NAMESPACE, 'xpath')] : null,
        ];
    }

    /** @param array<string,mixed> $control */
    private function docxControlPath(array $control): ?string
    {
        $tag = $this->canonicalIdentity((string) ($control['tag'] ?? ''));
        if ($tag !== null) {
            return $tag;
        }
        $binding = $control['data_binding']['xpath'] ?? null;
        if (! is_string($binding) || ! preg_match('#^/runtime/([A-Za-z][A-Za-z0-9_]*(?:/[A-Za-z][A-Za-z0-9_]*)+)$#', $binding, $match)) {
            return null;
        }

        return implode('.', explode('/', $match[1]));
    }

    private function canonicalIdentity(string $identity): ?string
    {
        $identity = trim($identity);
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/i', $identity) === 1) {
            return strtolower($identity);
        }
        // Technical identifiers may use a Word/Excel-safe double-underscore
        // namespace, e.g. spmu__request__number. This is explicit metadata,
        // not a conversion of human labels or a fuzzy field guess.
        if (str_starts_with(strtolower($identity), 'spmu__')) {
            $segments = explode('__', substr($identity, 6));
            if (count($segments) >= 2 && ! array_filter($segments, fn (string $segment): bool => preg_match('/^[a-z][a-z0-9_]*$/i', $segment) !== 1)) {
                return strtolower(implode('.', $segments));
            }
        }

        return null;
    }

    private function isSignaturePath(string $path): bool
    {
        return str_contains($path, 'signature');
    }

    /** @param array<string,mixed> $context */
    private function contextValue(array $context, string $path, ?bool &$found = null): mixed
    {
        $cursor = $context['namespaces'] ?? [];
        foreach (explode('.', $path) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                $found = false;

                return null;
            }
            $cursor = $cursor[$segment];
        }
        $found = true;

        return $cursor;
    }

    private function isScalarValue(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value instanceof \Stringable;
    }

    private function stringValue(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            default => (string) $value,
        };
    }

    /** @return array{0:string,1:string,2:?string}|null */
    private function xlsxReference(string $reference): ?array
    {
        $reference = trim(ltrim($reference, '='));
        if (preg_match("/^(?:'((?:[^']|'')+)'|([A-Za-z_][A-Za-z0-9_. ]*))!\\$?([A-Z]{1,3})\\$?(\\d+)(?::\\$?([A-Z]{1,3})\\$?(\\d+))?$/", $reference, $match) !== 1) {
            return null;
        }
        $sheet = str_replace("''", "'", $match[1] !== '' ? $match[1] : $match[2]);
        $start = $match[3].$match[4];
        $end = isset($match[5], $match[6]) && $match[5] !== '' ? $match[5].$match[6] : null;

        return [$sheet, $start, $end];
    }

    /** @return array{0:int,1:int,2:int,3:int}|null */
    private function xlsxTableRange(string $reference): ?array
    {
        if (preg_match('/^([A-Z]{1,3})(\d+):([A-Z]{1,3})(\d+)$/', $reference, $match) !== 1) {
            return null;
        }
        $firstColumn = $this->xlsxColumnNumber($match[1]);
        $lastColumn = $this->xlsxColumnNumber($match[3]);
        $firstRow = (int) $match[2];
        $lastRow = (int) $match[4];

        return $firstColumn <= $lastColumn && $firstRow < $lastRow
            ? [$firstColumn, $firstRow, $lastColumn, $lastRow]
            : null;
    }

    private function xlsxColumnNumber(string $column): int
    {
        $number = 0;
        foreach (str_split($column) as $character) {
            $number = ($number * 26) + (ord($character) - 64);
        }

        return $number;
    }

    private function xlsxColumnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(($number % 26) + 65).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function safeItemColumnKey(string $name): ?string
    {
        $name = strtolower(trim($name));

        return preg_match('/^[a-z][a-z0-9_]*$/', $name) === 1 ? $name : null;
    }

    private function xlsxCell(DOMXPath $xpath, string $reference): ?DOMElement
    {
        $cell = $xpath->query('//x:sheetData/x:row/x:c[@r="'.$reference.'"]')->item(0);

        return $cell instanceof DOMElement ? $cell : null;
    }

    private function isExactMergedRange(DOMXPath $xpath, string $start, string $end): bool
    {
        foreach ($xpath->query('//x:mergeCells/x:mergeCell') as $merge) {
            if ($merge instanceof DOMElement && strtoupper($merge->getAttribute('ref')) === strtoupper($start.':'.$end)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,int> $styleCache */
    private function writeXlsxCellText(DOMDocument $document, DOMXPath $xpath, DOMDocument $styles, DOMXPath $stylesXpath, array &$styleCache, DOMElement $cell, string $value): void
    {
        if ($xpath->query('./x:f', $cell)->length > 0) {
            $this->block('A resolved XLSX data region contains a formula and cannot be overwritten safely.');
        }
        foreach (['f', 'v', 'is'] as $name) {
            foreach ($xpath->query('./x:'.$name, $cell) as $child) {
                $cell->removeChild($child);
            }
        }
        $inline = $document->createElementNS(self::XLSX_NAMESPACE, 'is');
        $text = $document->createElementNS(self::XLSX_NAMESPACE, 't');
        if ($value !== trim($value)) {
            $text->setAttributeNS(self::XML_NAMESPACE, 'xml:space', 'preserve');
        }
        $text->nodeValue = $value;
        $inline->appendChild($text);
        $cell->setAttribute('t', 'inlineStr');
        $cell->setAttribute('s', (string) $this->wrappedXlsxStyle($styles, $stylesXpath, $styleCache, (int) $cell->getAttribute('s')));
        $cell->appendChild($inline);
        $row = $cell->parentNode;
        if ($row instanceof DOMElement) {
            $this->relaxXlsxRowHeight($row);
        }
    }

    /** @param array<string,int> $styleCache */
    private function wrappedXlsxStyle(DOMDocument $styles, DOMXPath $xpath, array &$styleCache, int $baseStyle): int
    {
        if (isset($styleCache[(string) $baseStyle])) {
            return $styleCache[(string) $baseStyle];
        }
        $cellXfs = $xpath->query('//x:cellXfs')->item(0);
        $base = $xpath->query('//x:cellXfs/x:xf')->item($baseStyle);
        if (! $cellXfs instanceof DOMElement || ! $base instanceof DOMElement) {
            $this->block('The XLSX Draft has no supported cell style to preserve for wrapped text.');
        }
        $clone = $base->cloneNode(true);
        if (! $clone instanceof DOMElement) {
            $this->block('The XLSX Draft cell style could not be cloned safely.');
        }
        $alignment = $xpath->query('./x:alignment', $clone)->item(0);
        if (! $alignment instanceof DOMElement) {
            $alignment = $styles->createElementNS(self::XLSX_NAMESPACE, 'alignment');
            $clone->appendChild($alignment);
        }
        $alignment->setAttribute('wrapText', '1');
        $clone->setAttribute('applyAlignment', '1');
        $index = $xpath->query('//x:cellXfs/x:xf')->length;
        $cellXfs->appendChild($clone);
        $cellXfs->setAttribute('count', (string) ($index + 1));
        $styleCache[(string) $baseStyle] = $index;

        return $index;
    }

    private function relaxXlsxRowHeight(DOMElement $row): void
    {
        $row->removeAttribute('ht');
        $row->removeAttribute('customHeight');
    }

    private function xlsxWorksheetPartForName(ZipArchive $zip, string $sheetName): ?string
    {
        $xml = $zip->getFromName('xl/workbook.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (! is_string($xml) || ! is_string($relationships)) {
            return null;
        }
        [, $workbookXpath] = $this->xlsxXml($xml, 'xl/workbook.xml');
        [$relsDocument, $relsXpath] = $this->xml($relationships, 'XLSX', 'xl/_rels/workbook.xml.rels', ['r' => self::RELATIONSHIPS_NAMESPACE]);
        unset($relsDocument);
        foreach ($workbookXpath->query('//x:sheets/x:sheet') as $sheet) {
            if (! $sheet instanceof DOMElement || $sheet->getAttribute('name') !== $sheetName) {
                continue;
            }
            $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            foreach ($relsXpath->query('//r:Relationship') as $relationship) {
                if ($relationship instanceof DOMElement && $relationship->getAttribute('Id') === $relationshipId) {
                    return $this->resolvePartPath('xl/workbook.xml', $relationship->getAttribute('Target'));
                }
            }
        }

        return null;
    }

    private function xlsxWorksheetPartForTable(ZipArchive $zip, string $tablePart): ?string
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $relationshipPart = $zip->getNameIndex($index);
            if (! is_string($relationshipPart) || preg_match('#^xl/worksheets/_rels/[^/]+\.xml\.rels$#', $relationshipPart) !== 1) {
                continue;
            }
            $xml = $zip->getFromIndex($index);
            if (! is_string($xml)) {
                continue;
            }
            [, $xpath] = $this->xml($xml, 'XLSX', $relationshipPart, ['r' => self::RELATIONSHIPS_NAMESPACE]);
            foreach ($xpath->query('//r:Relationship') as $relationship) {
                if (! $relationship instanceof DOMElement || $this->resolvePartPath($this->relationshipOwnerPart($relationshipPart), $relationship->getAttribute('Target')) !== $tablePart) {
                    continue;
                }

                return $this->relationshipOwnerPart($relationshipPart);
            }
        }

        return null;
    }

    private function relationshipOwnerPart(string $relationshipPart): string
    {
        return preg_replace('#/_rels/([^/]+)\.rels$#', '/$1', $relationshipPart) ?: $relationshipPart;
    }

    private function resolvePartPath(string $basePart, string $target): string
    {
        $segments = [];
        foreach (explode('/', dirname($basePart).'/'.str_replace('\\', '/', $target)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function assertSafeOfficeParts(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $part = $zip->getNameIndex($index);
            if (! is_string($part)) {
                continue;
            }
            if (preg_match('#(?:^|/)(?:vbaProject\.bin|activeX/|embeddings/)#i', $part) === 1) {
                $this->block('The Office Draft contains executable or embedded content and cannot be rendered safely.');
            }
            if (! str_ends_with(strtolower($part), '.rels')) {
                continue;
            }
            $xml = $zip->getFromIndex($index);
            if (is_string($xml) && preg_match('/TargetMode=["\']External["\']/i', $xml) === 1) {
                $this->block('The Office Draft references an external resource and cannot be rendered safely.');
            }
        }
    }

    private function convertWorkingOfficeSource(string $bytes, string $format): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'spmu-office-draft-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['template' => 'Could not prepare a secure Office Draft rendering workspace.']);
        }
        $source = $directory.DIRECTORY_SEPARATOR.'draft.'.strtolower($format);
        try {
            if (file_put_contents($source, $bytes) === false) {
                throw ValidationException::withMessages(['template' => 'Could not write the temporary Office Draft clone.']);
            }
            $process = new Process([
                'soffice', '--headless', '--nologo', '--nodefault', '--nolockcheck', '--norestore',
                '--convert-to', 'pdf', '--outdir', $directory, $source,
            ]);
            $process->setTimeout(45);
            $process->run();
            $pdfPath = $directory.DIRECTORY_SEPARATOR.'draft.pdf';
            if (! $process->isSuccessful() || ! is_file($pdfPath)) {
                throw ValidationException::withMessages(['template' => 'The Office Draft could not be rendered safely.']);
            }
            $pdf = file_get_contents($pdfPath);
            if (! is_string($pdf) || $pdf === '') {
                throw ValidationException::withMessages(['template' => 'The Office Draft renderer produced no PDF output.']);
            }

            return $pdf;
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    private function assertImportablePdf(string $bytes): int
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            throw ValidationException::withMessages(['template' => 'The Office Draft renderer did not produce a valid PDF.']);
        }
        $path = tempnam(sys_get_temp_dir(), 'spmu-office-pdf-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw ValidationException::withMessages(['template' => 'Could not validate the rendered Office Draft PDF.']);
        }
        try {
            $pdf = new Fpdi('P', 'pt');
            $pages = $pdf->setSourceFile($path);
            if ($pages < 1) {
                throw ValidationException::withMessages(['template' => 'The Office Draft renderer produced an empty PDF.']);
            }
            for ($page = 1; $page <= $pages; $page++) {
                $imported = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($imported);
                if (($size['width'] ?? 0) <= 0 || ($size['height'] ?? 0) <= 0) {
                    throw ValidationException::withMessages(['template' => 'The Office Draft renderer produced an unreadable PDF page.']);
                }
            }

            return $pages;
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $extra @return array<string,mixed> */
    private function warning(string $severity, string $code, string $message, array $extra = []): array
    {
        return ['severity' => $severity, 'code' => $code, 'message' => $message, ...$extra];
    }

    /** @param list<array<string,mixed>> $warnings @return list<array<string,mixed>> */
    private function uniqueWarnings(array $warnings): array
    {
        $unique = [];
        foreach ($warnings as $warning) {
            $key = json_encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (is_string($key)) {
                $unique[$key] = $warning;
            }
        }

        return array_values($unique);
    }

    private function block(string $message): never
    {
        throw ValidationException::withMessages(['template' => $message]);
    }
}

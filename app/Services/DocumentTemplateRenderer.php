<?php

namespace App\Services;

use App\Models\DocumentTemplate;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Produces controlled documents from an activated, immutable PDF render
 * representation. The approved layout is always imported as the page
 * background; transaction values are written only at confirmed positions.
 */
class DocumentTemplateRenderer
{
    public function __construct(
        private ProtectedFileService $files,
        private DocumentTemplateLayoutService $layouts,
    ) {}

    /**
     * Returns a production-safe PDF representation without altering the
     * submitted source. DOCX/XLSX conversion is deliberately done once at
     * registration, so preview and production share the exact same base PDF.
     *
     * @return array{bytes:string,representation:'DIRECT_PDF'|'NORMALIZED_PDF'}
     */
    public function renderRepresentation(UploadedFile $upload, string $format): array
    {
        $bytes = (string) file_get_contents($upload->getRealPath());
        if ($format === 'PDF') {
            if ($this->assertImportablePdf($bytes, true)) {
                return ['bytes' => $bytes, 'representation' => 'DIRECT_PDF'];
            }

            return ['bytes' => $this->normalizePdf($bytes), 'representation' => 'NORMALIZED_PDF'];
        }

        if (! in_array($format, ['DOCX', 'XLSX'], true)) {
            throw ValidationException::withMessages(['template_file' => 'The uploaded layout has no supported production renderer.']);
        }

        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'spmu-template-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['template_file' => 'Could not prepare a secure document-rendering workspace.']);
        }

        $extension = strtolower($format);
        $sourcePath = $directory.DIRECTORY_SEPARATOR.'approved-layout.'.$extension;

        try {
            file_put_contents($sourcePath, $bytes);
            $process = new Process([
                'soffice', '--headless', '--nologo', '--nodefault', '--nolockcheck',
                '--convert-to', 'pdf', '--outdir', $directory, $sourcePath,
            ]);
            $process->setTimeout(45);
            $process->run();

            $renderPath = $directory.DIRECTORY_SEPARATOR.'approved-layout.pdf';
            if (! $process->isSuccessful() || ! is_file($renderPath)) {
                throw ValidationException::withMessages([
                    'template_file' => 'This layout cannot be activated because a production-ready document could not be generated.',
                ]);
            }

            $renderBytes = (string) file_get_contents($renderPath);
            $this->assertImportablePdf($renderBytes);

            return ['bytes' => $renderBytes, 'representation' => 'NORMALIZED_PDF'];
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'template_file' => 'This layout cannot be activated because a production-ready document could not be generated.',
            ]);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    public function canRender(DocumentTemplate $template): bool
    {
        try {
            $this->assertReady($template);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function pageCount(DocumentTemplate $template): int
    {
        $path = $this->temporaryPdfPath($this->renderFile($template));

        try {
            return (new Fpdi('P', 'pt'))->setSourceFile($path);
        } catch (Throwable) {
            return 1;
        } finally {
            @unlink($path);
        }
    }

    /** @param array<string,mixed> $data */
    public function render(DocumentTemplate $template, array $data): string
    {
        $configuration = $this->assertReady($template);
        $path = $this->temporaryPdfPath($this->renderFile($template));

        try {
            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($path);
            $mappings = collect($configuration['mappings'])->keyBy('field');
            $tableLayouts = $configuration['table_layouts'];
            $definitions = $this->layouts->fieldDefinitions($template->document_type);

            for ($page = 1; $page <= $pageCount; $page++) {
                $imported = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($imported);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($imported, 0, 0, $size['width'], $size['height']);

                $pageTables = [];
                foreach ($mappings as $field => $mapping) {
                    if ((int) $mapping['page'] !== $page || ! isset($definitions[$field])) {
                        continue;
                    }

                    $table = $definitions[$field]['table'] ?? null;
                    if ($table !== null) {
                        $pageTables[$table][$field] = $mapping;
                        continue;
                    }

                    $this->writeValue(
                        $pdf,
                        $mapping,
                        $mappings->all(),
                        $data[$field] ?? '',
                        $size,
                    );
                }

                foreach ($pageTables as $table => $mappingsForTable) {
                    $hasItemColumns = collect(array_keys($mappingsForTable))
                        ->contains(fn (string $field): bool => str_starts_with($field, 'items.'));
                    $rows = $hasItemColumns
                        ? (is_array($data['items'] ?? null) ? $data['items'] : [])
                        : ($table === 'release_return' ? [$data] : []);
                    $this->writeTableRows(
                        $pdf,
                        $table,
                        $mappingsForTable,
                        $mappings->all(),
                        $tableLayouts,
                        $rows,
                        $data,
                        $size,
                    );
                }
            }

            $bytes = $pdf->Output('S');
            $this->assertImportablePdf($bytes);

            return $bytes;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'template' => 'This layout cannot be activated because a production-ready document could not be generated.',
            ]);
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    public function sampleData(string $type): array
    {
        $sample = $this->layouts->sampleValues($type);
        $definitions = $this->layouts->fieldDefinitions($type);

        // Preview values are demonstrably synthetic, yet travel through the
        // same semantic mappings and renderer as production. Production only
        // receives immutable signature snapshots from its workflow payload.
        foreach ($definitions as $field => $definition) {
            if (! str_ends_with($field, '_signature') || ! isset($definition['signatory'])) {
                continue;
            }

            $prefix = substr($field, 0, -strlen('_signature'));
            $sample[$field] ??= [
                'kind' => 'signature_placeholder',
                'label' => 'SAMPLE E-SIGNATURE',
            ];
            $sample[$prefix.'_printed_name'] ??= 'SAMPLE '.str($prefix)->replace('_', ' ')->upper()->toString();
            $sample[$prefix.'_designation'] ??= 'Sample Authorized Officer';
            $sample[$prefix.'_date'] ??= (string) ($sample['document_date'] ?? $sample['issued_date'] ?? '10 September 2026');
        }

        $items = [];
        foreach ($definitions as $field => $definition) {
            if (! str_starts_with($field, 'items.')) {
                continue;
            }
            $key = substr($field, strlen('items.'));
            $items[$key] = (string) ($sample[$field] ?? $this->sampleTableValue($key, (string) ($definition['label'] ?? 'Value')));
        }

        return [
            ...$sample,
            'items' => [$items],
        ];
    }

    private function sampleTableValue(string $key, string $label): string
    {
        return match (true) {
            str_contains($key, 'qty') || str_contains($key, 'quantity') => '1',
            str_contains($key, 'date') => '10 September 2026',
            str_contains($key, 'time') => '2:15 PM',
            str_contains($key, 'amount') || str_contains($key, 'value') => 'PHP 150.00',
            str_contains($key, 'condition') => 'Serviceable',
            str_contains($key, 'remark') || str_contains($key, 'finding') => 'Sample recorded finding',
            default => 'Sample '.$label,
        };
    }

    /** @return array{mappings:list<array<string,mixed>>,table_layouts:array<string,array{row_height_percent:float,max_rows:int}>} */
    private function assertReady(DocumentTemplate $template): array
    {
        $template->loadMissing('renderFile');
        if ($template->source_mode !== 'OFFICIAL_LAYOUT' || ! $template->renderFile) {
            throw ValidationException::withMessages(['template' => 'The active layout has no production render representation.']);
        }

        $configuration = $this->layouts->configuration($template);
        $analysis = $this->layouts->analysis(
            $template->document_type,
            $configuration['mappings'],
            $configuration['analysis']['suggestions'] ?? [],
            $configuration['table_layouts'],
        );
        if (! $analysis['ready']) {
            throw ValidationException::withMessages(['template' => 'The active layout is missing confirmed production positions or table settings.']);
        }

        return [
            'mappings' => $configuration['mappings'],
            'table_layouts' => $configuration['table_layouts'],
        ];
    }

    private function renderFile(DocumentTemplate $template): StoredFile
    {
        $file = $template->renderFile;
        if (! $file) {
            throw ValidationException::withMessages(['template' => 'The active layout has no production render representation.']);
        }

        return $file;
    }

    private function temporaryPdfPath(StoredFile $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spmu-render-');
        if ($path === false) {
            throw ValidationException::withMessages(['template' => 'Could not prepare the controlled document renderer.']);
        }

        file_put_contents($path, $this->files->bytes($file));

        return $path;
    }

    /** @param array<string,mixed> $mapping @param array<string,array<string,mixed>> $allMappings @param array{width:float,height:float} $size */
    private function writeValue(Fpdi $pdf, array $mapping, array $allMappings, mixed $value, array $size): void
    {
        if ($value === '' || $value === null) {
            return;
        }

        $x = ((float) $mapping['x'] / 100) * $size['width'];
        $y = ((float) $mapping['y'] / 100) * $size['height'];
        $width = $this->availableWidth($mapping, $allMappings, $size);
        if (is_numeric($mapping['max_width_percent'] ?? null)) {
            $width = min($width, ((float) $mapping['max_width_percent'] / 100) * $size['width']);
        }
        $maximumHeight = is_numeric($mapping['max_height_percent'] ?? null)
            ? ((float) $mapping['max_height_percent'] / 100) * $size['height']
            : 14.0;

        if ($this->isCheckboxMapping($mapping)) {
            if ($this->isCheckedCheckboxValue($value)) {
                $this->drawCheckmark($pdf, $x, $y, $width, $maximumHeight);
            }

            return;
        }

        if (is_array($value) && $this->writeSignatureValue($pdf, $mapping, $x, $y, $width, $maximumHeight, $value)) {
            return;
        }
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return;
        }

        $this->writeFittedText($pdf, $x, $y, $width, (string) $value);
    }

    /** @param array<string,mixed> $mapping */
    private function isCheckboxMapping(array $mapping): bool
    {
        if (($mapping['field_type'] ?? null) === 'checkbox') {
            return true;
        }

        // Older persisted generic mappings did not retain field_type. The
        // suffix is a semantic convention, not a document-specific field.
        return str_ends_with((string) ($mapping['field'] ?? ''), '_checkbox');
    }

    private function isCheckedCheckboxValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (! is_scalar($value) && ! $value instanceof \Stringable) {
            return false;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'no', 'unchecked'], true);
    }

    /** Draw a centered vector tick inside the detected checkbox region. */
    private function drawCheckmark(Fpdi $pdf, float $x, float $y, float $width, float $height): void
    {
        $side = min($width, $height);
        if ($side <= 0.8) {
            return;
        }
        $left = $x + (($width - $side) / 2);
        $top = $y + (($height - $side) / 2);
        $pdf->SetDrawColor(20, 20, 20);
        $pdf->SetLineWidth(max(0.55, min(1.15, $side * 0.13)));
        $pdf->Line($left + $side * 0.20, $top + $side * 0.52, $left + $side * 0.43, $top + $side * 0.75);
        $pdf->Line($left + $side * 0.43, $top + $side * 0.75, $left + $side * 0.82, $top + $side * 0.25);
    }

    /**
     * Render only a trusted signature asset supplied by DocumentService, or a
     * visibly-labelled preview placeholder. Signature bytes never come from
     * an uploaded template or browser input.
     *
     * @param array<string,mixed> $value
     * @param array<string,mixed> $mapping
     */
    private function writeSignatureValue(Fpdi $pdf, array $mapping, float $x, float $y, float $width, float $maximumHeight, array $value): bool
    {
        $kind = (string) ($value['kind'] ?? '');
        if ($kind === 'signature_placeholder') {
            $label = trim((string) ($value['label'] ?? 'SAMPLE E-SIGNATURE'));
            $pdf->SetFont('Helvetica', 'I', 5.5);
            $pdf->SetXY($x + 1.5, $y + max(0.0, ($maximumHeight - 7.0) / 2));
            $pdf->Cell(max(1.0, $width - 3.0), 7.0, $this->pdfText($label), 0, 0, 'C');

            return true;
        }
        if ($kind !== 'signature_image' || ! is_string($value['bytes'] ?? null) || $value['bytes'] === '') {
            return false;
        }

        $bytes = $value['bytes'];
        $image = @getimagesizefromstring($bytes);
        if (! is_array($image) || empty($image[0]) || empty($image[1])) {
            Log::warning('Document template signature image could not be read.', [
                'operation' => 'template_signature_image',
                'field' => (string) ($mapping['field'] ?? ''),
                'mime_type' => (string) ($value['mime_type'] ?? ''),
            ]);

            return true;
        }

        $type = match (strtolower((string) ($image['mime'] ?? $value['mime_type'] ?? ''))) {
            'image/jpeg', 'image/jpg' => 'JPEG',
            'image/png' => 'PNG',
            default => null,
        };
        if ($type === null) {
            // Registered WebP signatures are converted in memory only for
            // FPDF, which accepts PNG/JPEG streams. No signature file or
            // workflow record is altered.
            if (! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
                Log::warning('Document template signature image format is unavailable to FPDF.', [
                    'operation' => 'template_signature_image',
                    'field' => (string) ($mapping['field'] ?? ''),
                    'mime_type' => (string) ($value['mime_type'] ?? ''),
                ]);

                return true;
            }
            $source = @imagecreatefromstring($bytes);
            if ($source === false) {
                Log::warning('Document template signature image conversion failed.', [
                    'operation' => 'template_signature_image',
                    'field' => (string) ($mapping['field'] ?? ''),
                    'mime_type' => (string) ($value['mime_type'] ?? ''),
                ]);

                return true;
            }
            ob_start();
            imagepng($source);
            $bytes = (string) ob_get_clean();
            imagedestroy($source);
            $image = @getimagesizefromstring($bytes);
            $type = 'PNG';
        }

        if (! is_array($image) || empty($image[0]) || empty($image[1])) {
            return true;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'spmu-signature-');
        if ($temporary === false) {
            return true;
        }

        try {
            if (file_put_contents($temporary, $bytes) === false) {
                return true;
            }
            $maxWidth = max(8.0, $width - 3.0);
            $maxHeight = max(5.0, $maximumHeight - 1.0);
            $ratio = min($maxWidth / (float) $image[0], $maxHeight / (float) $image[1]);
            $renderedWidth = max(1.0, (float) $image[0] * $ratio);
            $renderedHeight = max(1.0, (float) $image[1] * $ratio);
            $pdf->Image(
                $temporary,
                $x + (($width - $renderedWidth) / 2),
                $y + (($maximumHeight - $renderedHeight) / 2),
                $renderedWidth,
                $renderedHeight,
                $type,
            );
        } finally {
            @unlink($temporary);
        }

        return true;
    }

    /**
     * Render a complete row at once so the tallest wrapped cell controls the
     * height of every cell in that row. This preserves the approved table
     * boundary and makes the preview use the same production layout path.
     *
     * @param array<string,array<string,mixed>> $mappingsForTable
     * @param array<string,array<string,mixed>> $allMappings
     * @param array<string,array<string,mixed>> $tableLayouts
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $data
     * @param array{width:float,height:float} $size
     */
    private function writeTableRows(Fpdi $pdf, string $table, array $mappingsForTable, array $allMappings, array $tableLayouts, array $rows, array $data, array $size): void
    {
        if ($rows === []) {
            return;
        }

        $layout = $tableLayouts[$table] ?? null;
        if (! is_array($layout)) {
            // Existing activated layouts may predate boundary metadata. Keep
            // their established one-line behavior until they are prepared
            // again, rather than inventing a table boundary at render time.
            foreach ($mappingsForTable as $field => $mapping) {
                foreach ($rows as $index => $row) {
                    $value = str_starts_with($field, 'items.')
                        ? (string) ($row[substr($field, strlen('items.'))] ?? '')
                        : ($index === 0 ? (string) ($data[$field] ?? '') : '');
                    $legacyMapping = $mapping;
                    if (str_starts_with($field, 'items.')) {
                        $legacyMapping['y'] = (float) $mapping['y']
                            + ((float) ($tableLayouts[$table]['row_height_percent'] ?? 1.8) * $index);
                    }
                    $this->writeValue($pdf, $legacyMapping, $allMappings, $value, $size);
                }
            }

            return;
        }
        if (count($rows) > (int) ($layout['max_rows'] ?? 0)) {
            throw ValidationException::withMessages([
                'template' => 'This document contains more item text than the approved form can display safely.',
            ]);
        }

        uasort($mappingsForTable, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);
        $grid = $this->tableGrid($layout, $mappingsForTable);
        $columns = $this->tableColumns($mappingsForTable, $size, $grid);
        $minimumRowHeight = ((float) $layout['row_height_percent'] / 100) * $size['height'];
        $firstRowY = $grid !== null
            ? ((float) $grid['body_top_percent'] / 100) * $size['height']
            : max(array_map(fn (array $mapping): float => ((float) $mapping['y'] / 100) * $size['height'], $mappingsForTable));
        $maximumHeight = is_numeric($layout['max_height_percent'] ?? null)
            ? ((float) $layout['max_height_percent'] / 100) * $size['height']
            : $minimumRowHeight * (int) $layout['max_rows'];
        $tableBottom = $grid !== null
            ? ((float) $grid['body_bottom_percent'] / 100) * $size['height']
            : $firstRowY + $maximumHeight;
        $cursorY = $firstRowY;
        $preparedRows = [];

        foreach ($rows as $index => $row) {
            $cells = [];
            $rowHeight = $minimumRowHeight;
            foreach ($mappingsForTable as $field => $mapping) {
                $value = str_starts_with($field, 'items.')
                    ? (string) ($row[substr($field, strlen('items.'))] ?? '')
                    : ($index === 0 ? (string) ($data[$field] ?? '') : '');
                $column = $columns[$field];
                $x = $column['x'];
                $width = $column['width'];
                $textWidth = max(12.0, $width - 3.0);
                $lines = $this->wrappedTableLines($pdf, $value, $textWidth);
                $lineHeight = 9.6;
                $requiredHeight = $lines === [] ? $minimumRowHeight : max($minimumRowHeight, count($lines) * $lineHeight + 2.6);
                $rowHeight = max($rowHeight, $requiredHeight);
                $cells[] = compact('field', 'x', 'textWidth', 'lines', 'lineHeight');
            }
            if ($cursorY + $rowHeight > $tableBottom + 0.01) {
                throw ValidationException::withMessages([
                    'template' => 'This document contains more item text than the approved form can display safely.',
                ]);
            }

            $preparedRows[] = compact('cells', 'rowHeight', 'cursorY');
            $cursorY += $rowHeight;
        }

        if ($grid !== null) {
            $this->redrawTableGrid($pdf, $grid, $preparedRows, $minimumRowHeight, $size);
        }

        foreach ($preparedRows as $preparedRow) {
            foreach ($preparedRow['cells'] as $cell) {
                if ($cell['lines'] === []) {
                    continue;
                }
                $textHeight = count($cell['lines']) * $cell['lineHeight'];
                $y = $preparedRow['cursorY'] + max(1.3, ($preparedRow['rowHeight'] - $textHeight) / 2);
                $alignment = $this->tableCellAlignment((string) $cell['field']);
                $pdf->SetFont('Helvetica', '', 8.0);
                foreach ($cell['lines'] as $line) {
                    $pdf->SetXY($cell['x'] + 1.5, $y);
                    $pdf->Cell($cell['textWidth'], $cell['lineHeight'], $this->pdfText($line), 0, 0, $alignment);
                    $y += $cell['lineHeight'];
                }
            }
        }
    }

    private function tableCellAlignment(string $field): string
    {
        $field = strtolower($field);

        if (str_contains($field, 'amount') || str_contains($field, 'value')) {
            return 'R';
        }

        if (str_contains($field, 'qty')
            || str_contains($field, 'quantity')
            || str_contains($field, 'unit')
            || str_contains($field, 'date')
            || str_contains($field, 'time')) {
            return 'C';
        }

        return 'L';
    }

    /** @param array<string,mixed> $layout @param array<string,array<string,mixed>> $mappingsForTable @return array<string,mixed>|null */
    private function tableGrid(array $layout, array $mappingsForTable): ?array
    {
        $grid = $layout['grid'] ?? null;
        if (! is_array($grid)
            || ! is_numeric($grid['page'] ?? null)
            || ! is_numeric($grid['left_percent'] ?? null)
            || ! is_numeric($grid['right_percent'] ?? null)
            || ! is_numeric($grid['body_top_percent'] ?? null)
            || ! is_numeric($grid['body_bottom_percent'] ?? null)
            || ! is_array($grid['vertical_boundaries_percent'] ?? null)) {
            return null;
        }
        $page = (int) $grid['page'];
        if (collect($mappingsForTable)->contains(fn (array $mapping): bool => (int) ($mapping['page'] ?? 0) !== $page)) {
            return null;
        }

        return $grid;
    }

    /**
     * Use actual detected table-cell boundaries when present.  The fallback
     * remains constrained by adjacent mapping positions for older activated
     * templates, but it does not permit a value to spill into a neighbour.
     *
     * @param array<string,array<string,mixed>> $mappingsForTable
     * @param array<string,mixed>|null $grid
     * @param array{width:float,height:float} $size
     * @return array<string,array{x:float,width:float}>
     */
    private function tableColumns(array $mappingsForTable, array $size, ?array $grid): array
    {
        $boundaries = $grid !== null
            ? array_values(array_filter(array_map('floatval', $grid['vertical_boundaries_percent']), fn (float $value): bool => $value >= (float) $grid['left_percent'] - 0.1 && $value <= (float) $grid['right_percent'] + 0.1))
            : [];
        sort($boundaries, SORT_NUMERIC);
        $columns = [];
        $ordered = array_values($mappingsForTable);
        foreach ($ordered as $index => $mapping) {
            $mappedX = (float) $mapping['x'];
            $left = null;
            $right = null;
            foreach ($boundaries as $boundaryIndex => $boundary) {
                if ($boundary <= $mappedX + 0.6) {
                    $left = $boundary;
                }
                if ($boundary > $mappedX + 0.6) {
                    $right = $boundary;
                    break;
                }
            }
            if ($left === null || $right === null || $right - $left < 0.8) {
                $left = $mappedX;
                $right = isset($ordered[$index + 1]) ? (float) $ordered[$index + 1]['x'] - 0.45 : 97.0;
            }
            if (is_numeric($mapping['max_width_percent'] ?? null)) {
                $right = min($right, $left + (float) $mapping['max_width_percent']);
            }
            $columns[(string) $mapping['field']] = [
                'x' => (($left + 0.25) / 100) * $size['width'],
                'width' => max(12.0, (($right - $left - 0.5) / 100) * $size['width']),
            ];
        }

        return $columns;
    }

    /**
     * The original background has fixed row rules.  When generic geometry
     * confirms a blank table body, replace only that bounded body and redraw
     * its existing column edges plus the calculated row edges.  This keeps
     * wrapped rows inside the approved table, without touching a following
     * terms, notes, signatory, or footer region.
     *
     * @param array<string,mixed> $grid
     * @param list<array{cells:list<array<string,mixed>>,rowHeight:float,cursorY:float}> $rows
     * @param array{width:float,height:float} $size
     */
    private function redrawTableGrid(Fpdi $pdf, array $grid, array $rows, float $minimumRowHeight, array $size): void
    {
        $left = ((float) $grid['left_percent'] / 100) * $size['width'];
        $right = ((float) $grid['right_percent'] / 100) * $size['width'];
        $top = ((float) $grid['body_top_percent'] / 100) * $size['height'];
        $bottom = ((float) $grid['body_bottom_percent'] / 100) * $size['height'];
        if ($right <= $left || $bottom <= $top) {
            return;
        }

        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($left, $top, $right - $left, $bottom - $top, 'F');
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.35);
        $boundaries = array_values(array_unique(array_map('floatval', $grid['vertical_boundaries_percent'])));
        sort($boundaries, SORT_NUMERIC);
        foreach ($boundaries as $boundary) {
            if ($boundary < (float) $grid['left_percent'] - 0.1 || $boundary > (float) $grid['right_percent'] + 0.1) {
                continue;
            }
            $x = ($boundary / 100) * $size['width'];
            $pdf->Line($x, $top, $x, $bottom);
        }
        $pdf->Line($left, $top, $right, $top);
        foreach ($rows as $row) {
            $bottomLine = min($bottom, $row['cursorY'] + $row['rowHeight']);
            $pdf->Line($left, $bottomLine, $right, $bottomLine);
        }
        // Retain the remaining blank-row rhythm where it fits.  Dynamic rows
        // consume approved body height first; unused capacity remains visibly
        // table-shaped rather than being converted into an unofficial blank
        // block.
        $lastContentBottom = $rows === []
            ? $top
            : min($bottom, (float) $rows[array_key_last($rows)]['cursorY'] + (float) $rows[array_key_last($rows)]['rowHeight']);
        while ($lastContentBottom + $minimumRowHeight < $bottom - 0.35) {
            $lastContentBottom += $minimumRowHeight;
            $pdf->Line($left, $lastContentBottom, $right, $lastContentBottom);
        }
        // Preserve the approved outer lower boundary when content uses only
        // part of the available body height.
        $pdf->Line($left, $bottom, $right, $bottom);
    }

    /** @return list<string> */
    private function wrappedTableLines(Fpdi $pdf, string $value, float $width): array
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return [];
        }

        $pdf->SetFont('Helvetica', '', 8.0);
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $value) ?: [] as $word) {
            $candidate = $line === '' ? $word : $line.' '.$word;
            if ($pdf->GetStringWidth($this->pdfText($candidate)) <= $width) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
                $line = '';
            }
            foreach (mb_str_split($word) as $character) {
                $candidate = $line.$character;
                if ($line !== '' && $pdf->GetStringWidth($this->pdfText($candidate)) > $width) {
                    $lines[] = $line;
                    $line = $character;
                } else {
                    $line = $candidate;
                }
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /** @param array<string,mixed> $mapping @param array<string,array<string,mixed>> $allMappings @param array{width:float,height:float} $size */
    private function availableWidth(array $mapping, array $allMappings, array $size, ?string $sameTable = null): float
    {
        $x = ((float) $mapping['x'] / 100) * $size['width'];
        $nextX = $size['width'] - 18;
        foreach ($allMappings as $candidate) {
            if ((int) ($candidate['page'] ?? 0) !== (int) ($mapping['page'] ?? 0)
                || abs((float) ($candidate['y'] ?? 0) - (float) ($mapping['y'] ?? 0)) > 1.0
                || (float) ($candidate['x'] ?? 0) <= (float) $mapping['x']) {
                continue;
            }
            if ($sameTable !== null && ! str_starts_with((string) ($candidate['field'] ?? ''), 'items.')) {
                continue;
            }
            $candidateX = ((float) $candidate['x'] / 100) * $size['width'];
            $nextX = min($nextX, $candidateX - 5);
        }

        return max(32.0, $nextX - $x);
    }

    private function writeFittedText(Fpdi $pdf, float $x, float $y, float $width, string $value): void
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return;
        }

        // Matrix rows on an approved form can be intentionally compact. Use
        // a smaller readable type size before rejecting the value; this keeps
        // the complete name/date inside its confirmed cell rather than
        // crossing a row boundary or silently truncating it.
        foreach ([9.0, 8.0, 7.0, 6.0, 5.0, 4.5, 4.0] as $size) {
            $pdf->SetFont('Helvetica', '', $size);
            $encoded = $this->pdfText($value);
            if ($pdf->GetStringWidth($encoded) <= $width) {
                $pdf->SetXY($x, $y);
                $pdf->Cell($width, $size + 2, $encoded, 0, 0, 'L');

                return;
            }
        }

        throw ValidationException::withMessages([
            'template' => 'A transaction value does not fit in the approved mapped space. The layout needs a wider field; text was not overlapped or truncated.',
        ]);
    }

    private function pdfText(string $value): string
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value) ?: $value;
    }

    private function assertImportablePdf(string $bytes, bool $allowCompatibilityFallback = false): bool
    {
        if (! str_starts_with(ltrim($bytes), '%PDF-')) {
            throw ValidationException::withMessages([
                'template_file' => 'The uploaded file could not be read as a valid PDF.',
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'spmu-check-');
        if ($path === false) {
            throw ValidationException::withMessages([
                'template_file' => 'This PDF is valid but could not be prepared for document generation.',
            ]);
        }

        try {
            @chmod($path, 0600);
            if (file_put_contents($path, $bytes) === false) {
                throw new \RuntimeException('Could not write the temporary PDF import file.');
            }

            $pdf = new Fpdi('P', 'pt');
            $pageCount = $pdf->setSourceFile($path);
            if ($pageCount < 1) {
                throw new \RuntimeException('FPDI found no importable pages in the PDF.');
            }

            for ($page = 1; $page <= $pageCount; $page++) {
                $template = $pdf->importPage($page);
                $size = $pdf->getTemplateSize($template);
                if (! isset($size['width'], $size['height']) || (float) $size['width'] <= 0 || (float) $size['height'] <= 0) {
                    throw new \RuntimeException("FPDI imported page {$page} with invalid dimensions.");
                }
            }
        } catch (Throwable $exception) {
            $diagnostic = trim(preg_replace('/[\r\n\t]+/', ' ', $exception->getMessage()) ?? '');
            Log::warning('Document template PDF import failed', [
                'exception' => $exception::class,
                'message' => mb_substr($diagnostic, 0, 500),
                'document_format' => 'PDF',
                'operation' => 'template_pdf_import',
            ]);

            $reason = strtolower($diagnostic);
            $userMessage = match (true) {
                preg_match('/encrypt|password|security|permission|protected/', $reason) === 1
                    => 'This PDF is password-protected or encrypted. Upload an unprotected copy.',
                preg_match('/corrupt|malformed|unexpected end|xref|cross-reference|trailer|invalid pdf|not a pdf|unable to find/', $reason) === 1
                    => 'The uploaded file could not be read as a valid PDF.',
                default => 'This PDF is valid but could not be prepared for document generation.',
            };

            if ($allowCompatibilityFallback && $this->isFpdiCompatibilityFailure($reason)) {
                return false;
            }

            throw ValidationException::withMessages(['template_file' => $userMessage]);
        } finally {
            @unlink($path);
        }

        return true;
    }

    private function isFpdiCompatibilityFailure(string $reason): bool
    {
        return preg_match('/compression technique.*not supported|unsupported.*(?:compression|object stream|filter|parser)|object stream.*not supported|free parser/', $reason) === 1;
    }

    private function normalizePdf(string $bytes): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'spmu-pdf-normalize-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages([
                'template_file' => 'This PDF could not be prepared for document generation.',
            ]);
        }

        $sourcePath = $directory.DIRECTORY_SEPARATOR.'approved-layout.pdf';
        $normalizedPath = $directory.DIRECTORY_SEPARATOR.'production-layout.pdf';

        try {
            if (file_put_contents($sourcePath, $bytes) === false) {
                throw new \RuntimeException('Could not write the temporary PDF normalization source.');
            }
            @chmod($sourcePath, 0600);

            $process = new Process([
                'gs',
                '-dSAFER',
                '-dBATCH',
                '-dNOPAUSE',
                '-dQUIET',
                '-sDEVICE=pdfwrite',
                '-dCompatibilityLevel=1.4',
                '-dPDFSETTINGS=/prepress',
                '-dEmbedAllFonts=true',
                '-dSubsetFonts=true',
                '-dAutoRotatePages=/None',
                '-sOutputFile='.$normalizedPath,
                $sourcePath,
            ]);
            $process->setTimeout(90);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($normalizedPath)) {
                throw new \RuntimeException('Ghostscript did not produce a normalized PDF (exit code '.($process->getExitCode() ?? 'unknown').').');
            }

            $normalized = file_get_contents($normalizedPath);
            if (! is_string($normalized) || $normalized === '') {
                throw new \RuntimeException('Ghostscript produced an empty normalized PDF.');
            }

            $this->assertImportablePdf($normalized);

            return $normalized;
        } catch (Throwable $exception) {
            $diagnostic = trim(preg_replace('/[\r\n\t]+/', ' ', $exception->getMessage()) ?? '');
            Log::warning('Document template PDF normalization failed', [
                'exception' => $exception::class,
                'message' => mb_substr($diagnostic, 0, 500),
                'document_format' => 'PDF',
                'operation' => 'template_pdf_normalization',
            ]);

            throw ValidationException::withMessages([
                'template_file' => 'This PDF could not be prepared for document generation.',
            ]);
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }
}

<?php

namespace App\Services;

/**
 * Configurable semantic interpreter for an already-read layout model.
 *
 * It deliberately has no document revision, filename or page-coordinate
 * rules.  A supported document type contributes only field aliases and
 * semantic hints through DocumentTemplateLayoutService::fieldDefinitions().
 */
class DocumentTemplateSemanticInterpreter
{
    public function __construct(private GenericPdfLayoutReader $pdfReader) {}

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @param array<string,mixed> $review
     * @param list<array<string,mixed>> $existingMappings
     * @return array{mappings:list<array<string,mixed>>,table_layouts:array<string,array<string,float|int>>}
     */
    public function supplementalMappings(array $definitions, array $review, array $existingMappings): array
    {
        $model = is_array($review['layout_model'] ?? null) ? $review['layout_model'] : [];
        $phrases = is_array($model['phrases'] ?? null) ? $model['phrases'] : [];
        if ($phrases === []) {
            return ['mappings' => [], 'table_layouts' => []];
        }
        $mapped = collect($existingMappings)->pluck('field')->filter()->all();
        $mappings = [];

        foreach ($definitions as $field => $definition) {
            if (($definition['manual'] ?? true)
                || ($definition['table'] ?? null) !== null
                || isset($definition['signatory'])
                || ! array_key_exists('field_type', $definition)
                || in_array($field, $mapped, true)) {
                continue;
            }
            $candidate = $this->bestLabel($definition, $phrases, $model);
            if (! $candidate) {
                continue;
            }
            $region = $this->writableRegion($candidate, $model, (string) ($definition['field_type'] ?? 'text'));
            $mapping = $this->mapping(
                $field,
                'PDF_SEMANTIC_ADJACENT_REGION',
                (float) $candidate['confidence'],
                (string) $candidate['text'],
                (int) $candidate['page'],
                (float) $region['x'],
                (float) $region['y'],
            );
            if (isset($region['width'])) {
                $mapping['max_width_percent'] = round((float) $region['width'], 2);
            }
            if (isset($region['height'])) {
                $mapping['max_height_percent'] = round((float) $region['height'], 2);
            }
            // Keep the detected control semantics with the generic mapping
            // so the renderer can draw a control appropriately without
            // knowing a document type or a field name.
            if (($definition['field_type'] ?? null) === 'checkbox') {
                $mapping['field_type'] = 'checkbox';
            }
            $mappings[] = $mapping;
        }

        $mappings = [...$mappings, ...$this->signatoryMappings($definitions, $phrases, $model, [...$existingMappings, ...$mappings])];

        return [
            'mappings' => $mappings,
            'table_layouts' => $this->nonRepeatingTableLayouts($definitions, [...$existingMappings, ...$mappings], $review, $model),
        ];
    }

    /** @param array<string,mixed> $definition @param list<array<string,mixed>> $phrases @param array<string,mixed> $model @return array<string,mixed>|null */
    private function bestLabel(array $definition, array $phrases, array $model): ?array
    {
        $best = null;
        foreach ($phrases as $phrase) {
            if (! is_array($phrase)) {
                continue;
            }
            if (($definition['placement'] ?? null) === 'header' && (float) ($phrase['y'] ?? 100) >= 34) {
                continue;
            }
            if (($definition['placement'] ?? null) === 'header' && $this->insideTable($phrase, $model)) {
                continue;
            }
            $score = 0.0;
            foreach ([(string) ($definition['label'] ?? ''), ...(array) ($definition['aliases'] ?? [])] as $index => $alias) {
                $candidate = $this->labelConfidence((string) ($phrase['text'] ?? ''), (string) $alias);
                if ($index === 0 && $candidate >= 0.98) {
                    $candidate += 0.01;
                }
                $score = max($score, $candidate);
            }
            $score *= max(0.5, (float) ($phrase['confidence'] ?? 1.0));
            if ($score < 0.84) {
                continue;
            }
            if (($definition['placement'] ?? null) === 'header') {
                // Header is a relative region (the upper third of its page),
                // not a copied coordinate from any official revision.
                $score *= 1.05;
            }
            if ($best === null || $score > (float) $best['confidence']) {
                $best = [...$phrase, 'confidence' => round($score, 2)];
            }
        }

        return $best;
    }

    /** @param array<string,mixed> $phrase @param array<string,mixed> $model */
    private function insideTable(array $phrase, array $model): bool
    {
        $centerX = (float) ($phrase['x'] ?? 0) + (float) ($phrase['width'] ?? 0) / 2;
        $centerY = (float) ($phrase['y'] ?? 0) + (float) ($phrase['height'] ?? 0) / 2;
        foreach ((array) ($model['tables'] ?? []) as $table) {
            if (! is_array($table) || (int) ($table['page'] ?? 0) !== (int) ($phrase['page'] ?? 0)) {
                continue;
            }
            // In many PDFs the visible header text sits just above the
            // detected first horizontal rule. Treat that text as part of
            // the grid too, otherwise a short semantic alias such as
            // "Date" can be mistaken for a top-of-page field.
            if ($centerX >= (float) ($table['x'] ?? 0) - 0.3
                && $centerX <= (float) ($table['x'] ?? 0) + (float) ($table['width'] ?? 0) + 0.3
                && $centerY >= (float) ($table['y'] ?? 0) - 1.8
                && $centerY <= (float) ($table['y'] ?? 0) + (float) ($table['height'] ?? 0) + 0.3) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $label @param array<string,mixed> $model @return array{x:float,y:float,width?:float,height?:float} */
    private function writableRegion(array $label, array $model, string $fieldType): array
    {
        $page = (int) $label['page'];
        $right = (float) $label['x'] + (float) $label['width'];
        $labelMiddle = (float) $label['y'] + (float) $label['height'] / 2;
        $regions = is_array($model['writable_regions'] ?? null) ? $model['writable_regions'] : [];
        $candidates = [];
        foreach ($regions as $region) {
            if (! is_array($region) || (int) ($region['page'] ?? 0) !== $page) {
                continue;
            }
            $x = (float) ($region['x'] ?? 0);
            $y = (float) ($region['y'] ?? 0);
            $width = (float) ($region['width'] ?? 0);
            $height = (float) ($region['height'] ?? 0);
            if ($width <= 0 || $height <= 0) {
                continue;
            }
            if ($fieldType === 'checkbox') {
                if ($x + $width > (float) $label['x'] + 2.5 || abs(($y + $height / 2) - $labelMiddle) > max(3.5, (float) $label['height'] * 2.5)) {
                    continue;
                }
                // A checkbox is a compact square region. Do not mistake a
                // nearby date/name underline for a check control merely
                // because both share a visual row.
                if ($width > max(3.0, $height * 2.5)) {
                    continue;
                }
                $candidates[] = ['score' => abs((float) $label['x'] - ($x + $width)) + abs($labelMiddle - ($y + $height / 2)), 'region' => $region];
                continue;
            }
            $sameRow = $x >= $right - 0.8 && abs(($y + $height / 2) - $labelMiddle) <= max(2.5, (float) $label['height'] * 2.5);
            $below = $y >= (float) $label['y'] + (float) $label['height'] - 0.5 && $y <= (float) $label['y'] + (float) $label['height'] + 4.5;
            if (! $sameRow && ! $below) {
                continue;
            }
            $candidates[] = ['score' => max(0.0, $x - $right) + abs($y - (float) $label['y']) + ($below ? 1.5 : 0), 'region' => $region];
        }
        usort($candidates, fn (array $left, array $right): int => (float) $left['score'] <=> (float) $right['score']);
        if ($candidates !== []) {
            $region = $candidates[0]['region'];
            return ['x' => (float) $region['x'] + 0.3, 'y' => (float) $region['y'] + min(0.35, (float) $region['height'] / 3), 'width' => max(0.7, (float) $region['width'] - 0.6), 'height' => max(0.7, (float) $region['height'] - 0.4)];
        }

        // Generic geometric fallback for layouts whose artwork has no
        // extractable rules. It remains relative to the matched label.
        if ($fieldType === 'checkbox') {
            $size = max(0.8, min(2.2, (float) $label['height'] * 1.15));
            return ['x' => max(0.0, (float) $label['x'] - $size - 0.4), 'y' => (float) $label['y'], 'width' => $size, 'height' => $size];
        }

        return ['x' => min(96.0, $right + 1.4), 'y' => (float) $label['y'], 'width' => max(3.0, 96.0 - $right - 1.4)];
    }

    /**
     * @param array<string,array<string,mixed>> $definitions
     * @param list<array<string,mixed>> $phrases
     * @param array<string,mixed> $model
     * @param list<array<string,mixed>> $existingMappings
     * @return list<array<string,mixed>>
     */
    private function signatoryMappings(array $definitions, array $phrases, array $model, array $existingMappings): array
    {
        $alreadyMapped = collect($existingMappings)->pluck('field')->filter()->all();
        $mappings = [];
        foreach ($definitions as $signatureField => $definition) {
            $hint = $definition['signatory'] ?? null;
            if (! is_array($hint) || ! str_ends_with($signatureField, '_signature')) {
                continue;
            }
            $prefix = substr($signatureField, 0, -strlen('_signature'));
            $header = $this->signatoryHeader($phrases, $hint);
            if (! $header) {
                continue;
            }
            $rows = $this->signatoryRows($phrases, $header);
            if ($rows === []) {
                continue;
            }
            foreach (['signature' => 'signature', 'printed_name' => 'printed_name', 'designation' => 'designation', 'date' => 'date'] as $suffix => $rowKey) {
                $field = $prefix.'_'.$suffix;
                if (! isset($definitions[$field]) || ($definitions[$field]['manual'] ?? true) || in_array($field, $alreadyMapped, true) || ! isset($rows[$rowKey])) {
                    continue;
                }
                $region = $this->matrixRegion($header, $rows[$rowKey], $phrases, $model);
                $mapping = $this->mapping(
                    $field,
                    'PDF_SEMANTIC_SIGNATORY_MATRIX',
                    (float) $header['confidence'],
                    (string) $header['text'].' / '.str_replace('_', ' ', $rowKey),
                    (int) $header['page'],
                    (float) $region['x'],
                    (float) $region['y'],
                );
                $mapping['max_width_percent'] = round((float) $region['width'], 2);
                if ($suffix === 'signature') {
                    $mapping['max_height_percent'] = round((float) $region['height'], 2);
                }
                $mappings[] = $mapping;
                $alreadyMapped[] = $field;
            }
        }

        return $mappings;
    }

    /** @param list<array<string,mixed>> $phrases @param array<string,mixed> $hint @return array<string,mixed>|null */
    private function signatoryHeader(array $phrases, array $hint): ?array
    {
        $after = $this->bestAlias($phrases, (array) ($hint['after_headers'] ?? []));
        $before = $this->bestAlias($phrases, (array) ($hint['before_headers'] ?? []));
        $best = null;
        foreach ($phrases as $phrase) {
            if (! is_array($phrase)) {
                continue;
            }
            $score = $this->bestAliasScore((string) ($phrase['text'] ?? ''), (array) ($hint['header_aliases'] ?? []));
            if ($score < 0.84) {
                continue;
            }
            if ($after && ((int) $after['page'] !== (int) $phrase['page'] || (float) $phrase['x'] <= (float) $after['x'])) {
                continue;
            }
            if ($before && ((int) $before['page'] !== (int) $phrase['page'] || (float) $phrase['x'] >= (float) $before['x'])) {
                continue;
            }
            $candidate = [...$phrase, 'confidence' => round($score * max(0.5, (float) ($phrase['confidence'] ?? 1.0)), 2)];
            if ($best === null || (float) $candidate['confidence'] > (float) $best['confidence']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** @param list<array<string,mixed>> $phrases @return array<string,mixed>|null */
    private function bestAlias(array $phrases, array $aliases): ?array
    {
        $best = null;
        foreach ($phrases as $phrase) {
            $score = $this->bestAliasScore((string) ($phrase['text'] ?? ''), $aliases);
            if ($score >= 0.84 && ($best === null || $score > (float) $best['confidence'])) {
                $best = [...$phrase, 'confidence' => $score];
            }
        }

        return $best;
    }

    /** @param list<array<string,mixed>> $phrases @param array<string,mixed> $header @return array<string,array<string,mixed>> */
    private function signatoryRows(array $phrases, array $header): array
    {
        $aliases = [
            'signature' => ['signature'],
            'printed_name' => ['printed name', 'printed'],
            'designation' => ['designation', 'position'],
            'date' => ['date'],
        ];
        $rows = [];
        $minimumY = (float) $header['y'] + max(0.4, (float) $header['height'] * 0.35);
        foreach ($aliases as $key => $rowAliases) {
            foreach ($phrases as $phrase) {
                if (! is_array($phrase) || (int) ($phrase['page'] ?? 0) !== (int) $header['page'] || (float) ($phrase['y'] ?? 0) <= $minimumY) {
                    continue;
                }
                $score = $this->bestAliasScore((string) ($phrase['text'] ?? ''), $rowAliases);
                if ($score < 0.84 || (isset($rows[$key]) && (float) $rows[$key]['y'] <= (float) $phrase['y'])) {
                    continue;
                }
                $rows[$key] = [...$phrase, 'confidence' => $score];
            }
        }

        return count($rows) === 4 ? $rows : [];
    }

    /** @param array<string,mixed> $header @param array<string,mixed> $row @param list<array<string,mixed>> $phrases @param array<string,mixed> $model @return array{x:float,y:float,width:float,height:float} */
    private function matrixRegion(array $header, array $row, array $phrases, array $model): array
    {
        $center = (float) $header['x'] + (float) $header['width'] / 2;
        $cells = is_array($model['cells'] ?? null) ? $model['cells'] : [];
        foreach ($cells as $cell) {
            if ((int) ($cell['page'] ?? 0) !== (int) $header['page']
                || $center < (float) $cell['x'] - 0.3
                || $center > (float) $cell['x'] + (float) $cell['width'] + 0.3
                || (float) $row['y'] < (float) $cell['y'] - 0.5
                || (float) $row['y'] > (float) $cell['y'] + (float) $cell['height'] + 0.5) {
                continue;
            }
            return ['x' => (float) $cell['x'] + 0.45, 'y' => (float) $cell['y'] + 0.25, 'width' => max(1.0, (float) $cell['width'] - 0.9), 'height' => max(0.8, (float) $cell['height'] - 0.5)];
        }

        $sameRow = array_values(array_filter($phrases, fn (array $phrase): bool => (int) ($phrase['page'] ?? 0) === (int) $header['page'] && abs((float) ($phrase['y'] ?? 0) - (float) $header['y']) <= max(0.9, (float) $header['height'])));
        usort($sameRow, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);
        $next = collect($sameRow)->first(fn (array $phrase): bool => (float) $phrase['x'] >= (float) $header['x'] + (float) $header['width'] + 0.8);
        $start = max(0.0, (float) $header['x'] - min(2.5, (float) $header['width'] * 0.3));
        $width = is_array($next) ? max(4.0, (float) $next['x'] - $start - 0.8) : max(4.0, 98.0 - $start);

        return ['x' => $start, 'y' => (float) $row['y'], 'width' => $width, 'height' => max(0.8, (float) $row['height'] + 0.8)];
    }

    /** @param array<string,array<string,mixed>> $definitions @param list<array<string,mixed>> $mappings @param array<string,mixed> $review @param array<string,mixed> $model @return array<string,array<string,float|int>> */
    private function nonRepeatingTableLayouts(array $definitions, array $mappings, array $review, array $model): array
    {
        $layouts = [];
        foreach (array_unique(array_filter(array_map(fn (array $definition): ?string => $definition['table'] ?? null, $definitions))) as $table) {
            $hasRepeatingFields = false;
            foreach ($definitions as $field => $definition) {
                if (($definition['table'] ?? null) === $table && str_starts_with($field, 'items.')) {
                    $hasRepeatingFields = true;
                    break;
                }
            }
            if ($hasRepeatingFields) {
                continue;
            }
            $fields = array_keys(array_filter($definitions, fn (array $definition): bool => ($definition['table'] ?? null) === $table && ! ($definition['manual'] ?? true)));
            $tableMappings = array_values(array_filter($mappings, fn (array $mapping): bool => in_array((string) ($mapping['field'] ?? ''), $fields, true)));
            if (count($tableMappings) !== count($fields) || $tableMappings === []) {
                continue;
            }
            $page = (int) $tableMappings[0]['page'];
            $firstY = max(array_map(fn (array $mapping): float => (float) $mapping['y'], $tableMappings));
            $tableModel = collect((array) ($model['tables'] ?? []))->first(function (array $candidate) use ($page, $firstY): bool {
                return (int) ($candidate['page'] ?? 0) === $page
                    && $firstY >= (float) ($candidate['y'] ?? 0) - 0.5
                    && $firstY <= (float) ($candidate['y'] ?? 0) + (float) ($candidate['height'] ?? 0) + 0.5;
            });
            $grid = $this->pdfReader->tableGridForMappings($tableMappings, $model);
            $available = $grid !== null
                ? (float) $grid['body_bottom_percent'] - (float) $grid['body_top_percent']
                : (is_array($tableModel)
                    ? (float) $tableModel['y'] + (float) $tableModel['height'] - $firstY - 0.35
                    : $this->nextSectionY($review, $page, $firstY) - $firstY - 2.6);
            if ($available < 1.2) {
                continue;
            }
            $layouts[$table] = [
                'row_height_percent' => round($grid !== null ? (float) $grid['baseline_row_height_percent'] : min(2.2, $available), 2),
                'max_rows' => $grid !== null ? max(1, (int) $grid['row_capacity']) : 1,
                'max_height_percent' => round($available, 2),
            ];
            if ($grid !== null) {
                $layouts[$table]['grid'] = $grid;
            }
        }

        return $layouts;
    }

    /** @param array<string,mixed> $review */
    private function nextSectionY(array $review, int $page, float $firstY): float
    {
        $next = array_filter((array) ($review['layout_lines'] ?? []), fn ($line): bool => is_array($line) && (int) ($line['page'] ?? 0) === $page && (float) ($line['y'] ?? 0) > $firstY + 1.2);
        return min(array_map(fn (array $line): float => (float) $line['y'], $next) ?: [0.0]);
    }

    /** @return array<string,mixed> */
    private function mapping(string $field, string $method, float $confidence, string $label, int $page, float $x, float $y): array
    {
        return ['field' => $field, 'target' => 'click:automatic-semantic-interpreter', 'method' => $method, 'confidence' => round($confidence, 2), 'location_label' => $label, 'repeating' => str_starts_with($field, 'items.'), 'page' => $page, 'x' => round($x, 2), 'y' => round($y, 2)];
    }

    /** @param list<string> $aliases */
    private function bestAliasScore(string $text, array $aliases): float
    {
        return max(array_map(fn (string $alias): float => $this->labelConfidence($text, $alias), array_filter(array_map('strval', $aliases))) ?: [0.0]);
    }

    private function labelConfidence(string $candidate, string $alias): float
    {
        $candidate = $this->normalise($candidate);
        $alias = $this->normalise($alias);
        if ($candidate === '' || $alias === '') {
            return 0.0;
        }
        if ($candidate === $alias) {
            return 1.0;
        }
        if (str_contains($candidate, $alias) || str_contains($alias, $candidate)) {
            return min(0.96, min(strlen($candidate), strlen($alias)) / max(strlen($candidate), strlen($alias)) + 0.18);
        }
        $distance = levenshtein($candidate, $alias);
        return max(0.0, 1 - ($distance / max(strlen($candidate), strlen($alias))));
    }

    private function normalise(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}

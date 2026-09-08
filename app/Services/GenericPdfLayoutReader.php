<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Revision-agnostic PDF reader.
 *
 * This class knows nothing about a business document type.  It turns an
 * importable PDF into a normalized, page-relative layout model that a
 * document-type interpreter can consume.  The model is intentionally held
 * in memory while preparing a template; raw document text is not persisted.
 */
class GenericPdfLayoutReader
{
    /**
     * @return array{source:string,words:list<array<string,mixed>>,lines:list<array<string,mixed>>,model:array<string,mixed>}
     */
    public function read(string $bytes): array
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'spmu-generic-layout-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return $this->emptyResult();
        }

        $sourcePath = $directory.DIRECTORY_SEPARATOR.'source.pdf';
        try {
            if (file_put_contents($sourcePath, $bytes) === false) {
                return $this->emptyResult();
            }
            @chmod($sourcePath, 0600);

            $textProcess = new Process(['pdftotext', '-bbox-layout', '-enc', 'UTF-8', $sourcePath, '-']);
            $textProcess->setTimeout(45);
            $textProcess->run();
            $words = $textProcess->isSuccessful() ? $this->parsePopplerBoundingBoxes($textProcess->getOutput()) : [];
            $source = $words === [] ? 'OCR' : 'TEXT_LAYER';

            // Raster pages are used for generic rule, box, cell and writable
            // region detection for both text and scanned PDFs.
            $images = $this->renderPages($sourcePath, $directory);
            if ($words === []) {
                foreach ($images as $page => $image) {
                    $ocr = new Process(['tesseract', $image, 'stdout', '-l', 'eng', 'tsv']);
                    $ocr->setTimeout(60);
                    $ocr->run();
                    if ($ocr->isSuccessful()) {
                        $words = [...$words, ...$this->parseTesseractTsv($ocr->getOutput(), $page, $image)];
                    }
                }
            }

            $lines = $this->lines($words);
            $model = $this->layoutModel($images, $words, $lines);

            return [
                'source' => $words === [] ? 'UNAVAILABLE' : $source,
                'words' => $words,
                'lines' => $lines,
                'model' => $model,
            ];
        } catch (Throwable) {
            return $this->emptyResult();
        } finally {
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
    }

    /**
     * Resolve a complete, redraw-safe table body from the reader's generic
     * cell model.  Callers supply only mappings already inferred from that
     * model; this method has no knowledge of document types or field names.
     *
     * @param list<array<string,mixed>> $mappings
     * @param array<string,mixed> $model
     * @return array<string,mixed>|null
     */
    public function tableGridForMappings(array $mappings, array $model): ?array
    {
        if ($mappings === []) {
            return null;
        }
        $tables = is_array($model['tables'] ?? null) ? $model['tables'] : [];
        $page = (int) ($mappings[0]['page'] ?? 0);
        foreach ($tables as $table) {
            if (! is_array($table) || (int) ($table['page'] ?? 0) !== $page) {
                continue;
            }
            $cells = is_array($table['cells'] ?? null) ? $table['cells'] : [];
            $rowCells = [];
            foreach ($mappings as $mapping) {
                $match = collect($cells)->first(function (mixed $cell) use ($mapping): bool {
                    return is_array($cell)
                        // A field position immediately below a rule must be
                        // matched to that body cell, not the preceding table
                        // header. Keep this tolerance below the collapsed
                        // raster-rule thickness; it remains generic to every
                        // layout and avoids treating a heading as writable.
                        && (float) ($mapping['x'] ?? -1) >= (float) ($cell['x'] ?? 0) - 0.15
                        && (float) ($mapping['x'] ?? -1) <= (float) ($cell['x'] ?? 0) + (float) ($cell['width'] ?? 0) + 0.15
                        && (float) ($mapping['y'] ?? -1) >= (float) ($cell['y'] ?? 0) - 0.15
                        && (float) ($mapping['y'] ?? -1) <= (float) ($cell['y'] ?? 0) + (float) ($cell['height'] ?? 0) + 0.15;
                });
                if (! is_array($match)) {
                    continue 2;
                }
                $rowCells[] = $match;
            }
            $bodyTop = min(array_map(fn (array $cell): float => (float) $cell['y'], $rowCells));
            if (max(array_map(fn (array $cell): float => abs((float) $cell['y'] - $bodyTop), $rowCells)) > 0.7) {
                continue;
            }
            $left = (float) ($table['x'] ?? -1);
            $right = $left + (float) ($table['width'] ?? 0);
            $bottom = (float) ($table['y'] ?? 0) + (float) ($table['height'] ?? 0);
            if ($left < 0 || $right <= $left || $bottom <= $bodyTop) {
                continue;
            }
            $boundaries = [];
            $rowStarts = [];
            $rowHeights = [];
            foreach ($cells as $cell) {
                if (! is_array($cell) || (float) ($cell['y'] ?? 0) < $bodyTop - 0.5) {
                    continue;
                }
                $boundaries[] = (float) $cell['x'];
                $boundaries[] = (float) $cell['x'] + (float) $cell['width'];
                $rowStarts[] = (float) $cell['y'];
                $rowHeights[] = (float) $cell['height'];
            }
            $boundaries[] = $left;
            $boundaries[] = $right;
            $boundaries = array_values(array_unique(array_map(fn (float $value): string => (string) round($value, 2), $boundaries)));
            sort($boundaries, SORT_NUMERIC);
            $rowStarts = array_values(array_unique(array_map(fn (float $value): string => (string) round($value, 2), $rowStarts)));
            sort($rowStarts, SORT_NUMERIC);
            $rowHeights = array_filter($rowHeights, fn (float $value): bool => $value > 0.7);
            if (count($boundaries) < 2 || $rowStarts === [] || $rowHeights === []) {
                continue;
            }

            return [
                'page' => $page,
                'left_percent' => round($left, 2),
                'right_percent' => round($right, 2),
                'body_top_percent' => round($bodyTop, 2),
                'body_bottom_percent' => round($bottom, 2),
                'vertical_boundaries_percent' => $boundaries,
                'row_capacity' => count($rowStarts),
                'baseline_row_height_percent' => round(min($rowHeights), 2),
            ];
        }

        return null;
    }

    /** @return array{source:string,words:list<array<string,mixed>>,lines:list<array<string,mixed>>,model:array<string,mixed>} */
    private function emptyResult(): array
    {
        return [
            'source' => 'UNAVAILABLE',
            'words' => [],
            'lines' => [],
            'model' => ['pages' => [], 'phrases' => [], 'rules' => [], 'boxes' => [], 'tables' => [], 'columns' => [], 'cells' => [], 'writable_regions' => []],
        ];
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
    private function renderPages(string $sourcePath, string $directory): array
    {
        $prefix = $directory.DIRECTORY_SEPARATOR.'page';
        $process = new Process(['pdftoppm', '-r', '120', '-png', $sourcePath, $prefix]);
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
    private function lines(array $words): array
    {
        usort($words, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y'], (float) $left['x']] <=> [(int) $right['page'], (float) $right['y'], (float) $right['x']]);
        $lines = [];
        foreach ($words as $word) {
            $last = array_key_last($lines);
            if ($last === null || (int) $lines[$last]['page'] !== (int) $word['page'] || abs((float) $lines[$last]['y'] - (float) $word['y']) > max(0.9, (float) $word['height'])) {
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

    /** @return array<string,mixed> */
    private function layoutModel(array $images, array $words, array $lines): array
    {
        $rules = [];
        $pages = [];
        foreach ($images as $page => $image) {
            $dimensions = @getimagesize($image);
            if (! is_array($dimensions) || ($dimensions[0] ?? 0) <= 0 || ($dimensions[1] ?? 0) <= 0) {
                continue;
            }
            $pages[] = ['page' => (int) $page, 'width' => (int) $dimensions[0], 'height' => (int) $dimensions[1]];
            $rules = [...$rules, ...$this->rasterRules($image, (int) $page, (int) $dimensions[0], (int) $dimensions[1])];
        }
        if ($pages === []) {
            foreach (array_unique(array_map(fn (array $word): int => (int) $word['page'], $words)) as $page) {
                $pages[] = ['page' => $page, 'width' => 0, 'height' => 0];
            }
        }
        $tables = $this->tables($rules);
        $cells = array_merge(...array_map(fn (array $table): array => $table['cells'], $tables) ?: [[]]);
        $columns = array_merge(...array_map(fn (array $table): array => $table['columns'], $tables) ?: [[]]);

        $phrases = $this->phrases($words);

        return [
            'pages' => $pages,
            'phrases' => [...$phrases, ...$this->multilinePhrases($phrases)],
            'rules' => $rules,
            'boxes' => $cells,
            'tables' => $tables,
            'columns' => $columns,
            'cells' => $cells,
            'writable_regions' => $this->writableRegions($rules, $cells, $words),
            'text_regions' => $lines,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function rasterRules(string $path, int $page, int $width, int $height): array
    {
        $image = @imagecreatefrompng($path);
        if ($image === false) {
            return [];
        }
        $horizontal = [];
        $vertical = [];
        $minimumHorizontal = max(24, (int) round($width * 0.06));
        $minimumVertical = max(24, (int) round($height * 0.045));
        for ($y = 0; $y < $height; $y++) {
            $horizontal = [...$horizontal, ...$this->darkRuns($image, $width, $height, $y, true, $minimumHorizontal, $page)];
        }
        for ($x = 0; $x < $width; $x++) {
            $vertical = [...$vertical, ...$this->darkRuns($image, $width, $height, $x, false, $minimumVertical, $page)];
        }
        imagedestroy($image);

        return [...$this->collapseRules($horizontal, 'horizontal', $page, $width, $height), ...$this->collapseRules($vertical, 'vertical', $page, $width, $height)];
    }

    /** @return list<array<string,mixed>> */
    private function darkRuns($image, int $width, int $height, int $fixed, bool $horizontal, int $minimum, int $page): array
    {
        $limit = $horizontal ? $width : $height;
        $start = null;
        $lastDark = null;
        $runs = [];
        for ($variable = 0; $variable < $limit; $variable++) {
            $x = $horizontal ? $variable : $fixed;
            $y = $horizontal ? $fixed : $variable;
            if ($this->dark($image, $x, $y)) {
                $start ??= $variable;
                $lastDark = $variable;
                continue;
            }
            // Thin vector rules can be antialiased by Poppler. Permit a very
            // short light gap while still rejecting ordinary text fragments.
            if ($start !== null && $lastDark !== null && $variable - $lastDark <= 2) {
                continue;
            }
            if ($start !== null && $lastDark !== null && $lastDark - $start + 1 >= $minimum) {
                $runs[] = ['page' => $page, 'orientation' => $horizontal ? 'horizontal' : 'vertical', 'fixed' => $fixed, 'start' => $start, 'end' => $lastDark];
            }
            $start = null;
            $lastDark = null;
        }
        if ($start !== null && $lastDark !== null && $lastDark - $start + 1 >= $minimum) {
            $runs[] = ['page' => $page, 'orientation' => $horizontal ? 'horizontal' : 'vertical', 'fixed' => $fixed, 'start' => $start, 'end' => $lastDark];
        }

        return $runs;
    }

    private function dark($image, int $x, int $y): bool
    {
        $color = imagecolorat($image, $x, $y);
        if (imageistruecolor($image)) {
            $red = ($color >> 16) & 0xff;
            $green = ($color >> 8) & 0xff;
            $blue = $color & 0xff;
        } else {
            $rgb = imagecolorsforindex($image, $color);
            $red = (int) ($rgb['red'] ?? 255);
            $green = (int) ($rgb['green'] ?? 255);
            $blue = (int) ($rgb['blue'] ?? 255);
        }

        return ($red + $green + $blue) / 3 < 205;
    }

    /** @return list<array<string,mixed>> */
    private function collapseRules(array $segments, string $orientation, int $page, int $width, int $height): array
    {
        usort($segments, fn (array $left, array $right): int => [(int) $left['fixed'], (int) $left['start']] <=> [(int) $right['fixed'], (int) $right['start']]);
        $groups = [];
        foreach ($segments as $segment) {
            $last = array_key_last($groups);
            if ($last !== null
                && abs((int) $groups[$last]['fixed'] - (int) $segment['fixed']) <= 3
                && min((int) $groups[$last]['end'], (int) $segment['end']) >= max((int) $groups[$last]['start'], (int) $segment['start']) - 4) {
                $groups[$last]['fixed'] = ((int) $groups[$last]['fixed'] + (int) $segment['fixed']) / 2;
                $groups[$last]['start'] = min((int) $groups[$last]['start'], (int) $segment['start']);
                $groups[$last]['end'] = max((int) $groups[$last]['end'], (int) $segment['end']);
                continue;
            }
            $groups[] = $segment;
        }

        return array_map(function (array $rule) use ($orientation, $page, $width, $height): array {
            return $orientation === 'horizontal'
                ? ['page' => $page, 'orientation' => $orientation, 'x' => round($rule['start'] / $width * 100, 2), 'y' => round($rule['fixed'] / $height * 100, 2), 'width' => round(($rule['end'] - $rule['start']) / $width * 100, 2), 'height' => 0.2]
                : ['page' => $page, 'orientation' => $orientation, 'x' => round($rule['fixed'] / $width * 100, 2), 'y' => round($rule['start'] / $height * 100, 2), 'width' => 0.2, 'height' => round(($rule['end'] - $rule['start']) / $height * 100, 2)];
        }, $groups);
    }

    /** @return list<array<string,mixed>> */
    private function tables(array $rules): array
    {
        $tables = [];
        foreach (array_unique(array_map(fn (array $rule): int => (int) $rule['page'], $rules)) as $page) {
            $horizontal = array_values(array_filter($rules, fn (array $rule): bool => (int) $rule['page'] === $page && $rule['orientation'] === 'horizontal'));
            $vertical = array_values(array_filter($rules, fn (array $rule): bool => (int) $rule['page'] === $page && $rule['orientation'] === 'vertical'));
            usort($vertical, fn (array $a, array $b): int => (float) $a['x'] <=> (float) $b['x']);
            foreach ($this->horizontalTableBands($horizontal) as $band) {
                $cells = [];
                foreach ($this->contiguousRuleSequences($band) as $sequence) {
                    for ($row = 0; $row < count($sequence) - 1; $row++) {
                        $top = $sequence[$row];
                        $bottom = $sequence[$row + 1];
                        $height = (float) $bottom['y'] - (float) $top['y'];
                        if ($height < 0.7 || $height > 20) {
                            continue;
                        }
                        $rowVertical = array_values(array_filter($vertical, fn (array $rule): bool => $this->verticalCovers($rule, (float) $top['y'], (float) $bottom['y'])));
                        for ($column = 0; $column < count($rowVertical) - 1; $column++) {
                            $left = $rowVertical[$column];
                            $right = $rowVertical[$column + 1];
                            $width = (float) $right['x'] - (float) $left['x'];
                            if ($width < 1.5 || $width > 95
                                || ! $this->horizontalCovers($top, (float) $left['x'], (float) $right['x'])
                                || ! $this->horizontalCovers($bottom, (float) $left['x'], (float) $right['x'])) {
                                continue;
                            }
                            $cells[] = ['page' => $page, 'x' => (float) $left['x'], 'y' => (float) $top['y'], 'width' => $width, 'height' => $height];
                        }
                    }
                }
                foreach ($this->cellClusters($cells) as $cluster) {
                    $xs = array_values(array_unique(array_map(fn (array $cell): string => (string) round((float) $cell['x'], 2), $cluster)));
                    if (count($xs) < 2 || count($cluster) < 4) {
                        continue;
                    }
                    sort($xs, SORT_NUMERIC);
                    $left = min(array_map(fn (array $cell): float => (float) $cell['x'], $cluster));
                    $top = min(array_map(fn (array $cell): float => (float) $cell['y'], $cluster));
                    $right = max(array_map(fn (array $cell): float => (float) $cell['x'] + (float) $cell['width'], $cluster));
                    $bottom = max(array_map(fn (array $cell): float => (float) $cell['y'] + (float) $cell['height'], $cluster));
                    $columns = [];
                    foreach ($xs as $x) {
                        $columnCells = array_values(array_filter($cluster, fn (array $cell): bool => abs((float) $cell['x'] - (float) $x) < 0.3));
                        $columns[] = ['page' => $page, 'x' => (float) $x, 'width' => max(array_map(fn (array $cell): float => (float) $cell['width'], $columnCells)), 'y' => $top, 'height' => $bottom - $top];
                    }
                    $tables[] = ['page' => $page, 'x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top, 'cells' => $cluster, 'columns' => $columns];
                }
            }
        }

        return $tables;
    }

    /** @return list<list<array<string,mixed>>> */
    private function horizontalTableBands(array $rules): array
    {
        $bands = [];
        foreach ($rules as $rule) {
            $matched = false;
            foreach ($bands as &$band) {
                if ($this->sameHorizontalBand($rule, $band[0])) {
                    $band[] = $rule;
                    $matched = true;
                    break;
                }
            }
            unset($band);
            if (! $matched) {
                $bands[] = [$rule];
            }
        }

        return $bands;
    }

    private function sameHorizontalBand(array $left, array $right): bool
    {
        $leftEnd = (float) $left['x'] + (float) $left['width'];
        $rightEnd = (float) $right['x'] + (float) $right['width'];
        $overlap = min($leftEnd, $rightEnd) - max((float) $left['x'], (float) $right['x']);
        $longer = max((float) $left['width'], (float) $right['width']);

        // A full-page border can contain a short table rule, but it is not
        // the same table band. Require comparable spans in both directions.
        return $longer > 0 && $overlap / $longer >= 0.82;
    }

    /** @return list<list<array<string,mixed>>> */
    private function contiguousRuleSequences(array $rules): array
    {
        usort($rules, fn (array $left, array $right): int => (float) $left['y'] <=> (float) $right['y']);
        if (count($rules) < 2) {
            return [];
        }
        $gaps = [];
        for ($index = 1; $index < count($rules); $index++) {
            $gap = (float) $rules[$index]['y'] - (float) $rules[$index - 1]['y'];
            if ($gap >= 0.7 && $gap <= 20) {
                $gaps[] = $gap;
            }
        }
        sort($gaps, SORT_NUMERIC);
        $nominal = $gaps === [] ? 2.0 : $gaps[(int) floor(count($gaps) / 2)];
        $maximumGap = max(1.5, min(9.0, $nominal * 1.8));
        $sequences = [[$rules[0]]];
        for ($index = 1; $index < count($rules); $index++) {
            $gap = (float) $rules[$index]['y'] - (float) $rules[$index - 1]['y'];
            if ($gap > $maximumGap) {
                $sequences[] = [];
            }
            $sequences[array_key_last($sequences)][] = $rules[$index];
        }

        return array_values(array_filter($sequences, fn (array $sequence): bool => count($sequence) >= 2));
    }

    private function verticalCovers(array $rule, float $top, float $bottom): bool
    {
        return (float) $rule['y'] <= $top + 0.5 && (float) $rule['y'] + (float) $rule['height'] >= $bottom - 0.5;
    }

    private function horizontalCovers(array $rule, float $left, float $right): bool
    {
        return (float) $rule['x'] <= $left + 0.5 && (float) $rule['x'] + (float) $rule['width'] >= $right - 0.5;
    }

    /** @return list<list<array<string,mixed>>> */
    private function cellClusters(array $cells): array
    {
        $clusters = [];
        while ($cells !== []) {
            $cluster = [array_shift($cells)];
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($cells as $index => $candidate) {
                    foreach ($cluster as $cell) {
                        $touchesX = abs(((float) $cell['x'] + (float) $cell['width']) - (float) $candidate['x']) < 0.6 || abs(((float) $candidate['x'] + (float) $candidate['width']) - (float) $cell['x']) < 0.6;
                        $touchesY = abs(((float) $cell['y'] + (float) $cell['height']) - (float) $candidate['y']) < 0.6 || abs(((float) $candidate['y'] + (float) $candidate['height']) - (float) $cell['y']) < 0.6;
                        $overlapX = min((float) $cell['x'] + (float) $cell['width'], (float) $candidate['x'] + (float) $candidate['width']) - max((float) $cell['x'], (float) $candidate['x']) > 0.4;
                        $overlapY = min((float) $cell['y'] + (float) $cell['height'], (float) $candidate['y'] + (float) $candidate['height']) - max((float) $cell['y'], (float) $candidate['y']) > 0.4;
                        if (($touchesX && $overlapY) || ($touchesY && $overlapX)) {
                            $cluster[] = $candidate;
                            unset($cells[$index]);
                            $changed = true;
                            break 2;
                        }
                    }
                }
            }
            $clusters[] = $cluster;
            $cells = array_values($cells);
        }

        return $clusters;
    }

    /** @return list<array<string,mixed>> */
    private function writableRegions(array $rules, array $cells, array $words): array
    {
        $regions = array_map(fn (array $cell): array => [...$cell, 'kind' => 'cell'], $cells);
        foreach ($rules as $rule) {
            if ($rule['orientation'] !== 'horizontal' || (float) $rule['width'] < 3 || (float) $rule['width'] > 70) {
                continue;
            }
            $hasText = collect($words)->contains(fn (array $word): bool => (int) $word['page'] === (int) $rule['page']
                && (float) $word['x'] < (float) $rule['x'] + (float) $rule['width']
                && (float) $word['x'] + (float) $word['width'] > (float) $rule['x']
                && abs(((float) $word['y'] + (float) $word['height']) - (float) $rule['y']) < 0.6);
            if (! $hasText) {
                $regions[] = ['page' => (int) $rule['page'], 'x' => (float) $rule['x'], 'y' => max(0.0, (float) $rule['y'] - 1.8), 'width' => (float) $rule['width'], 'height' => 1.6, 'kind' => 'underline'];
            }
        }

        return $regions;
    }

    /** @return list<array<string,mixed>> */
    private function phrases(array $words): array
    {
        usort($words, fn (array $left, array $right): int => [(int) $left['page'], (float) $left['y'], (float) $left['x']] <=> [(int) $right['page'], (float) $right['y'], (float) $right['x']]);
        $rows = [];
        foreach ($words as $word) {
            $last = array_key_last($rows);
            if ($last === null || (int) $rows[$last]['page'] !== (int) $word['page'] || abs((float) $rows[$last]['y'] - (float) $word['y']) > max(0.9, (float) $word['height'])) {
                $rows[] = ['page' => (int) $word['page'], 'y' => (float) $word['y'], 'words' => [$word]];
            } else {
                $rows[$last]['words'][] = $word;
            }
        }
        $phrases = [];
        foreach ($rows as $row) {
            $line = $row['words'];
            usort($line, fn (array $left, array $right): int => (float) $left['x'] <=> (float) $right['x']);
            for ($start = 0; $start < count($line); $start++) {
                $text = '';
                $left = (float) $line[$start]['x'];
                $top = (float) $line[$start]['y'];
                $right = $left;
                $bottom = $top;
                $confidence = 1.0;
                for ($end = $start; $end < min(count($line), $start + 6); $end++) {
                    $word = $line[$end];
                    $text .= ($end === $start ? '' : ' ').(string) $word['text'];
                    $right = max($right, (float) $word['x'] + (float) $word['width']);
                    $bottom = max($bottom, (float) $word['y'] + (float) $word['height']);
                    $confidence = min($confidence, (float) $word['confidence']);
                    $phrases[] = ['page' => (int) $row['page'], 'text' => $text, 'x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top, 'confidence' => $confidence];
                }
            }
        }

        return array_slice($phrases, 0, 2500);
    }

    /**
     * Combine vertically stacked labels without assigning any meaning to
     * them. This supports ordinary multi-line column headers in every form.
     *
     * @param list<array<string,mixed>> $phrases
     * @return list<array<string,mixed>>
     */
    private function multilinePhrases(array $phrases): array
    {
        $combined = [];
        foreach ($phrases as $top) {
            foreach ($phrases as $bottom) {
                if ((int) $top['page'] !== (int) $bottom['page']
                    || (float) $bottom['y'] <= (float) $top['y']
                    || (float) $bottom['y'] - ((float) $top['y'] + (float) $top['height']) > 3.5) {
                    continue;
                }
                $left = max((float) $top['x'], (float) $bottom['x']);
                $right = min((float) $top['x'] + (float) $top['width'], (float) $bottom['x'] + (float) $bottom['width']);
                if ($right - $left < min((float) $top['width'], (float) $bottom['width']) * 0.35) {
                    continue;
                }
                $text = trim((string) $top['text'].' '.(string) $bottom['text']);
                if (mb_strlen($text) > 100) {
                    continue;
                }
                $combined[] = [
                    'page' => (int) $top['page'],
                    'text' => $text,
                    'x' => min((float) $top['x'], (float) $bottom['x']),
                    'y' => (float) $top['y'],
                    'width' => max((float) $top['x'] + (float) $top['width'], (float) $bottom['x'] + (float) $bottom['width']) - min((float) $top['x'], (float) $bottom['x']),
                    'height' => (float) $bottom['y'] + (float) $bottom['height'] - (float) $top['y'],
                    'confidence' => min((float) $top['confidence'], (float) $bottom['confidence']),
                ];
            }
        }

        return array_slice($combined, 0, 2500);
    }

    private function xmlNumber(string $attributes, string $attribute): ?float
    {
        if (preg_match('/\b'.preg_quote($attribute, '/').'="([-+]?\d*\.?\d+)"/i', $attributes, $match) !== 1) {
            return null;
        }

        return (float) $match[1];
    }
}

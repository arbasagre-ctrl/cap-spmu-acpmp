<?php

namespace App\Reports\Exporters;

use App\Reports\ReportDataset;
use App\Reports\ReportExportOptions;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * A genuine, editable .docx of the report.
 *
 * This is real WordprocessingML written by PhpWord — not HTML renamed — so
 * the recipient can edit the table, add a note, and re-save it as a Word
 * document. The institutional header, the title block, the table and the
 * footer all match the printed copy.
 */
class WordExporter
{
    public function render(ReportDataset $dataset, ReportExportOptions $options): string
    {
        $word = new PhpWord;
        $word->getSettings()->setThemeFontLang(new Language(Language::EN_US));
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize($options->fontSize ?: 9);

        $margins = $options->marginsMm;

        $section = $word->addSection([
            'orientation' => $options->orientation,
            'pageSizeW' => $this->pageWidth($options),
            'pageSizeH' => $this->pageHeight($options),
            'marginTop' => $this->twips($margins['top']),
            'marginRight' => $this->twips($margins['right']),
            'marginBottom' => $this->twips($margins['bottom']),
            'marginLeft' => $this->twips($margins['left']),
        ]);

        $meta = $dataset->meta;

        $this->addInstitutionalHeader($section);
        $this->addTitle($section, $dataset, $meta);
        $this->addMetadata($section, $meta, $options);
        $this->addTable($section, $dataset, $options);

        if ($options->includeSummary && ! empty($dataset->summary)) {
            $this->addSummary($section, $dataset);
        }

        if ($options->includeFooter) {
            $this->addFooter($section);
        }

        return $this->output($word);
    }

    /**
     * Seal, then the three institutional lines. No document code, matching
     * the on-screen and printed header exactly.
     */
    private function addInstitutionalHeader(mixed $section): void
    {
        $table = $section->addTable(['borderSize' => 0, 'cellMargin' => 0]);
        $table->addRow();

        $sealCell = $table->addCell(1100, ['valign' => 'center']);
        $seal = is_file(public_path('images/cspc-seal-document.png'))
            ? public_path('images/cspc-seal-document.png')
            : public_path('images/cspc-logo.png');

        if (is_file($seal)) {
            $sealCell->addImage($seal, ['width' => 52, 'height' => 52]);
        }

        $textCell = $table->addCell(11000, ['valign' => 'center']);

        $textCell->addText('Republic of the Philippines', ['size' => 9], ['spaceAfter' => 0]);
        $textCell->addText(
            'CAMARINES SUR POLYTECHNIC COLLEGES',
            ['size' => 12, 'bold' => true],
            ['spaceAfter' => 0]
        );
        $textCell->addText('Nabua, Camarines Sur', ['size' => 9], ['spaceAfter' => 0]);

        /* The thin rule that closes the header. */
        $section->addTextBreak(0);
        $section->addText('', [], ['borderBottomSize' => 6, 'borderBottomColor' => '222222', 'spaceAfter' => 160]);
    }

    private function addTitle(mixed $section, ReportDataset $dataset, array $meta): void
    {
        $section->addText(
            mb_strtoupper($dataset->label),
            ['size' => 12, 'bold' => true],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
        );

        $period = \App\Reports\ReportCatalogue::periodMode($dataset->reportKey) === 'as_of'
            ? 'As of '.($meta['as_of_long'] ?? '')
            : 'For the period '.($meta['period_long'] ?? '');

        $section->addText(
            $period,
            ['size' => 9],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 200]
        );
    }

    private function addMetadata(mixed $section, array $meta, ReportExportOptions $options): void
    {
        if ($options->includeGeneratedBy && ! empty($meta['generated_by'])) {
            $section->addText('Prepared by     : '.$meta['generated_by'], ['size' => 9], ['spaceAfter' => 0]);
        }

        $section->addText(
            'Date Generated  : '.($meta['generated_long'] ?? ''),
            ['size' => 9],
            ['spaceAfter' => 0]
        );

        if ($options->includeFilters && ! empty($meta['applied_filters'])) {
            $filters = [];
            foreach ($meta['applied_filters'] as $label => $value) {
                $filters[] = $label.': '.$value;
            }

            $section->addText(
                'Applied Filters : '.implode('; ', $filters),
                ['size' => 9],
                ['spaceAfter' => 0]
            );
        }

        $section->addTextBreak(1);
    }

    private function addTable(mixed $section, ReportDataset $dataset, ReportExportOptions $options): void
    {
        $table = $section->addTable([
            'borderSize' => 6,
            'borderColor' => 'AAAAAA',
            'cellMargin' => 50,
            'width' => 100 * 50,
            'unit' => 'pct',
        ]);

        /*
         * tblHeader repeats the header row when the table breaks across
         * pages, which is the Word equivalent of the print rule.
         */
        $table->addRow(null, ['tblHeader' => $options->repeatHeaders]);

        foreach ($dataset->columns as $column) {
            $cell = $table->addCell(null, ['bgColor' => 'F2F2F2', 'valign' => 'bottom']);
            $cell->addText(
                mb_strtoupper((string) $column['label']),
                ['bold' => true, 'size' => 7.5],
                ['spaceAfter' => 0]
            );
        }

        if ($dataset->isEmpty()) {
            $table->addRow();
            $table->addCell(null, ['gridSpan' => max(1, count($dataset->columns))])
                ->addText(
                    \App\Reports\ReportCatalogue::emptyMessage($dataset->reportKey),
                    ['size' => 8],
                    ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
                );

            return;
        }

        foreach ($dataset->rows as $record) {
            $table->addRow();

            foreach ($dataset->columns as $column) {
                $alignment = ($column['align'] ?? null) === 'numeric' ? Jc::END : Jc::START;

                $table->addCell(null, ['valign' => 'top'])->addText(
                    (string) ($record[$column['key']] ?? ''),
                    ['size' => 8],
                    ['alignment' => $alignment, 'spaceAfter' => 0]
                );
            }
        }
    }

    private function addSummary(mixed $section, ReportDataset $dataset): void
    {
        $section->addTextBreak(1);
        $section->addText('SUMMARY', ['bold' => true, 'size' => 9], ['spaceAfter' => 60]);

        foreach ($dataset->summary as $label => $value) {
            $section->addText(
                $label.' : '.(is_numeric($value) ? number_format((float) $value) : $value),
                ['size' => 9],
                ['spaceAfter' => 0]
            );
        }
    }

    private function addFooter(mixed $section): void
    {
        $section->addTextBreak(1);
        $section->addText(
            'Prepared from official SPMU-ACPMP operational records.',
            ['size' => 8, 'color' => '444444'],
            ['spaceAfter' => 0]
        );
        $section->addText(
            'This is a system-generated report. No signature is required.',
            ['size' => 8, 'color' => '444444'],
            ['spaceAfter' => 0]
        );

        /* Word numbers its own pages, so the count is never invented here. */
        $footer = $section->addFooter();
        $footer->addPreserveText(
            'Page {PAGE} of {NUMPAGES}',
            ['size' => 8, 'color' => '444444'],
            ['alignment' => Jc::END]
        );
    }

    private function output(PhpWord $word): string
    {
        $file = tempnam(sys_get_temp_dir(), 'spmu-report-');

        \PhpOffice\PhpWord\IOFactory::createWriter($word, 'Word2007')->save($file);

        $contents = (string) file_get_contents($file);
        @unlink($file);

        return $contents;
    }

    /** Page dimensions in twips (1/1440 inch). */
    private function pageWidth(ReportExportOptions $options): int
    {
        [$width, $height] = $this->paperTwips($options->pageSize);

        return $options->orientation === 'landscape' ? $height : $width;
    }

    private function pageHeight(ReportExportOptions $options): int
    {
        [$width, $height] = $this->paperTwips($options->pageSize);

        return $options->orientation === 'landscape' ? $width : $height;
    }

    /** @return array{0:int,1:int} */
    private function paperTwips(string $pageSize): array
    {
        return match ($pageSize) {
            'LETTER' => [12240, 15840],
            'LEGAL' => [12240, 20160],
            default => [11906, 16838],
        };
    }

    private function twips(float $mm): int
    {
        return (int) round($mm * 56.6929);
    }
}

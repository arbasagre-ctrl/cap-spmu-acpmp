<?php

namespace App\Reports\Exporters;

use App\Reports\ReportDataset;
use App\Reports\ReportExportOptions;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A genuine .xlsx workbook of the report.
 *
 * The spreadsheet is data, not a picture of a document: no seal, no rules, no
 * decoration. Numbers are written as numbers and dates as real dates so the
 * recipient can sort, filter and total them, which is the only reason to hand
 * someone a spreadsheet rather than a PDF.
 */
class SpreadsheetExporter
{
    /**
     * The two date shapes the report builders produce. Nothing else is
     * treated as a date, so a purpose or a remark can never be reinterpreted
     * as one.
     */
    private const DATE_FORMATS = [
        'd M Y, g:i A' => 'dd mmm yyyy hh:mm AM/PM',
        'd M Y' => 'dd mmm yyyy',
    ];

    public function render(ReportDataset $dataset, ReportExportOptions $options): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($dataset->label));

        $meta = $dataset->meta;
        $row = 1;

        /*
         * A short provenance block so an exported sheet can still be
         * identified once it is separated from the system that produced it.
         */
        $sheet->setCellValue([1, $row++], $dataset->label);
        $sheet->setCellValue([1, $row++], $meta['period_long'] ?? ($meta['period_label'] ?? ''));

        if ($options->includeGeneratedBy && ! empty($meta['generated_by'])) {
            $sheet->setCellValue([1, $row++], 'Prepared by: '.$meta['generated_by']);
        }

        $sheet->setCellValue([1, $row++], 'Date Generated: '.($meta['generated_long'] ?? ''));

        if ($options->includeFilters && ! empty($meta['applied_filters'])) {
            $filters = [];
            foreach ($meta['applied_filters'] as $label => $value) {
                $filters[] = $label.': '.$value;
            }

            $sheet->setCellValue([1, $row++], 'Applied Filters: '.implode('; ', $filters));
        }

        $row++;
        $headerRow = $row;

        foreach ($dataset->columns as $index => $column) {
            $sheet->setCellValue([$index + 1, $headerRow], $column['label']);
        }

        $lastColumn = max(1, count($dataset->columns));

        $sheet->getStyle([1, $headerRow, $lastColumn, $headerRow])
            ->getFont()->setBold(true);

        $sheet->getStyle([1, $headerRow, $lastColumn, $headerRow])
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('EFEFEF');

        $row = $headerRow + 1;

        foreach ($dataset->rows as $record) {
            foreach ($dataset->columns as $index => $column) {
                $this->writeCell(
                    $sheet,
                    $index + 1,
                    $row,
                    (string) ($record[$column['key']] ?? ''),
                    ($column['align'] ?? null) === 'numeric'
                );
            }

            $row++;
        }

        /* Freezing below the header keeps the columns readable while scrolling. */
        $sheet->freezePane([1, $headerRow + 1]);

        if ($dataset->count() > 0) {
            $sheet->setAutoFilter([1, $headerRow, $lastColumn, $row - 1]);
        }

        foreach (range(1, $lastColumn) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        if ($options->includeSummary && ! empty($dataset->summary)) {
            $row++;
            $sheet->setCellValue([1, $row], 'Summary');
            $sheet->getStyle([1, $row, 1, $row])->getFont()->setBold(true);
            $row++;

            foreach ($dataset->summary as $label => $value) {
                $sheet->setCellValue([1, $row], $label);

                if (is_numeric($value)) {
                    $sheet->setCellValueExplicit([2, $row], (float) $value, DataType::TYPE_NUMERIC);
                } else {
                    $sheet->setCellValue([2, $row], $value);
                }

                $row++;
            }
        }

        $sheet->getStyle([1, 1, $lastColumn, $row])
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP);

        return $this->output($spreadsheet);
    }

    /**
     * Write one cell, keeping numbers numeric and dates real dates.
     *
     * Values arrive pre-formatted from the dataset, so a value is converted
     * only when it matches one of the shapes the builders emit exactly.
     * Anything else is written as text rather than guessed at.
     */
    private function writeCell(mixed $sheet, int $column, int $row, string $value, bool $numericColumn): void
    {
        if ($value === '') {
            return;
        }

        if ($numericColumn && is_numeric(str_replace(',', '', $value))) {
            $sheet->setCellValueExplicit(
                [$column, $row],
                (float) str_replace(',', '', $value),
                DataType::TYPE_NUMERIC
            );

            return;
        }

        foreach (self::DATE_FORMATS as $phpFormat => $excelFormat) {
            /*
             * createFromFormat throws on a value that does not match, so the
             * attempt is guarded: a purpose or a remark must never be
             * reinterpreted as a date, and must never abort the export.
             */
            try {
                $parsed = Carbon::createFromFormat($phpFormat, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($phpFormat) === $value) {
                $sheet->setCellValue(
                    [$column, $row],
                    \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($parsed)
                );

                $sheet->getStyle([$column, $row, $column, $row])
                    ->getNumberFormat()
                    ->setFormatCode($excelFormat);

                return;
            }
        }

        $sheet->setCellValueExplicit([$column, $row], $value, DataType::TYPE_STRING);
    }

    private function output(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');

        return (string) ob_get_clean();
    }

    /** Excel rejects sheet names over 31 characters or carrying []:*?/\ */
    private function sheetTitle(string $label): string
    {
        return mb_substr(
            (string) preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $label),
            0,
            31
        );
    }
}

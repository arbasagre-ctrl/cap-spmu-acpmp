<?php

namespace App\Services;

use App\Reports\Exporters\PdfExporter;
use App\Reports\Exporters\SpreadsheetExporter;
use App\Reports\Exporters\WordExporter;
use App\Reports\ReportBuilder;
use App\Reports\ReportCatalogue;
use App\Reports\ReportDataset;
use App\Reports\ReportExportOptions;
use App\Reports\ReportFilters;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The entry point for generating a formal report.
 *
 * Screen, CSV and print all call this one method with the same filters, so a
 * report's records are produced exactly once per format-independent query.
 * Report-specific logic lives in the builder the catalogue names; this class
 * only resolves the builder and stamps the generated-report metadata every
 * output is required to carry.
 */
class ReportService
{
    /**
     * Generate a report, or return null when the report has not yet been
     * migrated onto the dataset pipeline (the legacy controller path still
     * serves those, unchanged).
     */
    public function generate(ReportFilters $filters, ?User $generatedBy = null): ?ReportDataset
    {
        $definition = ReportCatalogue::definition($filters->reportKey);

        if ($definition['builder'] === null) {
            return null;
        }

        /** @var ReportBuilder $builder */
        $builder = app($definition['builder']);

        return $builder->build($filters)->withMeta(
            $this->metadata($filters, $definition['label'], $generatedBy)
        );
    }

    /**
     * The provenance block shown on screen, written into the CSV header, and
     * printed: what was run, over what period, with which filters, how many
     * records it returned, by whom, and when.
     *
     * @return array<string, mixed>
     */
    private function metadata(
        ReportFilters $filters,
        string $label,
        ?User $generatedBy
    ): array {
        return [
            'report_name' => $label,
            'period_label' => $this->periodLabel($filters),

            /*
             * Formal long-form dates for the printed document. A range report
             * quotes both ends; a snapshot quotes the date it was taken.
             */
            'period_long' => $filters->from->format('d F Y').' – '.$filters->to->format('d F Y'),
            'as_of_long' => $filters->to->format('d F Y'),
            'generated_long' => now()->format('d F Y, g:i A'),
            'period_selection' => $filters->periodSelection,
            'period_from' => $filters->from->toDateString(),
            'period_to' => $filters->to->toDateString(),
            'academic_period' => $filters->selectedAcademicPeriod
                ? trim(
                    $filters->selectedAcademicPeriod->academic_year
                    .' · '.$filters->selectedAcademicPeriod->term_name
                )
                : null,
            'applied_filters' => $filters->describe(),
            'rejected_filters' => $filters->rejected(),
            'generated_by' => $generatedBy?->full_name,
            'generated_at' => now()->format('d M Y, g:i A'),
        ];
    }

    private function periodLabel(ReportFilters $filters): string
    {
        return $filters->from->isSameDay($filters->to)
            ? $filters->from->format('d M Y')
            : $filters->from->format('d M Y').' – '.$filters->to->format('d M Y');
    }

    /**
     * A clean, self-describing download filename.
     *
     * The period is part of the name because a report is only meaningful
     * alongside the dates it covers: a snapshot carries its as-of date, an
     * activity report the range it spans.
     */
    public function filename(ReportDataset $dataset, string $extension = 'csv'): string
    {
        $meta = $dataset->meta;

        $name = preg_replace('/[^A-Za-z0-9]+/', '_', $dataset->label);
        $name = trim((string) $name, '_');

        $period = ReportCatalogue::periodMode($dataset->reportKey) === 'as_of'
            ? (string) ($meta['period_to'] ?? '')
            : trim(
                (string) ($meta['period_from'] ?? '')
                .'_to_'
                .(string) ($meta['period_to'] ?? ''),
                '_'
            );

        $filename = $period === '' ? $name : $name.'_'.$period;

        /* Nothing that could escape a directory or confuse a download header. */
        $filename = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $filename);

        return trim($filename, '_-').'.'.$extension;
    }

    /**
     * Render a dataset in one of the supported export formats.
     *
     * Every format is handed the same already-built dataset, so the totals in
     * a spreadsheet cannot differ from those in the PDF or on screen.
     */
    public function renderExport(ReportDataset $dataset, ReportExportOptions $options): string
    {
        return match ($options->format) {
            'pdf' => app(PdfExporter::class)->render($dataset, $options),
            'docx' => app(WordExporter::class)->render($dataset, $options),
            'xlsx' => app(SpreadsheetExporter::class)->render($dataset, $options),
            default => $this->csv($dataset, $options),
        };
    }

    /** The MIME type a rendered export should be served with. */
    public function mimeType(string $format): string
    {
        return match ($format) {
            'pdf' => 'application/pdf',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'text/csv',
        };
    }

    /** CSV as a string, using the same writer the streamed export uses. */
    private function csv(ReportDataset $dataset, ReportExportOptions $options): string
    {
        $handle = fopen('php://temp', 'r+');
        $this->writeCsv($dataset, $handle, $options);
        rewind($handle);
        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Stream the dataset as CSV.
     *
     * The rows written here are ReportDataset::records(), the same values the
     * screen table renders, so the CSV cannot contain a different record set
     * from the report the user is looking at.
     */
    public function writeCsv(
        ReportDataset $dataset,
        mixed $handle,
        ?ReportExportOptions $options = null
    ): void {
        /*
         * CSV is the raw tabular form: headers and data, nothing else. No
         * seal, no document styling, and no provenance preamble that would
         * have to be skipped before the file could be parsed.
         */
        fputcsv($handle, $dataset->columnLabels());

        foreach ($dataset->records() as $record) {
            fputcsv($handle, $record);
        }
    }
}

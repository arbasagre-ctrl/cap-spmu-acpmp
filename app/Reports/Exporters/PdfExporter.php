<?php

namespace App\Reports\Exporters;

use App\Reports\ReportDataset;
use App\Reports\ReportExportOptions;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * The official PDF copy of a report.
 *
 * dompdf renders the same document Blade the browser preview uses, so the
 * printed record and the reviewed record are one design. Page numbering is
 * stamped from the canvas because that is the only place the real page count
 * is known — the footer never claims a page total it cannot see.
 */
class PdfExporter
{
    public function render(ReportDataset $dataset, ReportExportOptions $options): string
    {
        $html = view('reports.pdf', [
            'dataset' => $dataset,
            'options' => $options->contentToggles(),
            'exportOptions' => $options,
            /*
             * dompdf resolves no remote assets, so the seal travels with the
             * document as a data URI rather than a URL that will not load.
             */
            'sealSrc' => $this->seal(),
        ])->render();

        $pdfOptions = new Options;
        $pdfOptions->set('isRemoteEnabled', false);
        $pdfOptions->set('isHtml5ParserEnabled', true);
        $pdfOptions->set('defaultFont', 'Helvetica');

        $pdf = new Dompdf($pdfOptions);
        $pdf->setPaper($this->paper($options->pageSize), $options->orientation);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        $this->stampPageNumbers($pdf);

        return (string) $pdf->output();
    }

    /**
     * "Page X of Y" written once the layout is known, so the total is the
     * real one.
     */
    private function stampPageNumbers(Dompdf $pdf): void
    {
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('Helvetica');

        $canvas->page_text(
            $canvas->get_width() - 110,
            $canvas->get_height() - 26,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            7.5,
            [0.28, 0.28, 0.28],
        );
    }

    private function paper(string $pageSize): string
    {
        return match ($pageSize) {
            'LETTER' => 'letter',
            'LEGAL' => 'legal',
            default => 'a4',
        };
    }

    private function seal(): ?string
    {
        /*
         * The compact seal, not the full-resolution one: it is drawn at 44pt,
         * so the large source adds nothing a reader can see while costing
         * dompdf a great deal of memory on every export.
         */
        foreach (['images/cspc-seal-document.png', 'images/cspc-logo.png'] as $candidate) {
            $path = public_path($candidate);

            if (is_file($path)) {
                return 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
            }
        }

        return null;
    }
}

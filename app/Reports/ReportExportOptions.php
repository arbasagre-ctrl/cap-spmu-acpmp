<?php

namespace App\Reports;

use Illuminate\Http\Request;

/**
 * Presentation options for one export.
 *
 * These control how a report is rendered, never what it contains: the dataset
 * is already built and validated before any of this applies. Anything the
 * request asks for that is not on the supported list is replaced by the
 * default rather than passed through.
 */
final class ReportExportOptions
{
    /** Formats the application can genuinely produce. */
    public const FORMATS = [
        'pdf' => 'PDF (.pdf)',
        'docx' => 'Microsoft Word (.docx)',
        'xlsx' => 'Microsoft Excel (.xlsx)',
        'csv' => 'CSV (.csv)',
        'print' => 'Print / Print Preview',
    ];

    /** The primary button label changes with the chosen format. */
    public const ACTION_LABELS = [
        'pdf' => 'Generate PDF',
        'docx' => 'Download Word',
        'xlsx' => 'Export XLSX',
        'csv' => 'Export CSV',
        'print' => 'Print Report',
    ];

    public const PAGE_SIZES = [
        'A4' => 'A4 (210 × 297 mm)',
        'LETTER' => 'Letter (8.5 × 11 in)',
        'LEGAL' => 'Legal (8.5 × 14 in)',
    ];

    public const ORIENTATIONS = [
        'portrait' => 'Portrait',
        'landscape' => 'Landscape',
    ];

    /**
     * Margin presets in millimetres, so the dialog can describe them in
     * language an administrator recognises instead of raw point values.
     *
     * @var array<string, array{label:string, mm:array{top:float,right:float,bottom:float,left:float}}>
     */
    public const MARGIN_PRESETS = [
        'normal' => ['label' => 'Normal (20 mm)', 'mm' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
        'narrow' => ['label' => 'Narrow (12 mm)', 'mm' => ['top' => 12, 'right' => 12, 'bottom' => 12, 'left' => 12]],
        'wide' => ['label' => 'Wide (32 mm)', 'mm' => ['top' => 32, 'right' => 32, 'bottom' => 32, 'left' => 32]],
        'custom' => ['label' => 'Custom', 'mm' => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20]],
    ];

    /**
     * @param  array{top:float,right:float,bottom:float,left:float}  $marginsMm
     */
    private function __construct(
        public readonly string $format,
        public readonly string $pageSize,
        public readonly string $orientation,
        public readonly string $marginPreset,
        public readonly array $marginsMm,
        public readonly bool $includeSummary,
        public readonly bool $includeGeneratedBy,
        public readonly bool $includeFilters,
        public readonly bool $includeFooter,
        public readonly bool $repeatHeaders,
        public readonly ?float $fontSize,
    ) {}

    public static function fromRequest(Request $request, string $reportKey): self
    {
        $format = strtolower((string) $request->input('format', 'pdf'));
        $format = array_key_exists($format, self::FORMATS) ? $format : 'pdf';

        $pageSize = strtoupper((string) $request->input('page_size', 'A4'));
        $pageSize = array_key_exists($pageSize, self::PAGE_SIZES) ? $pageSize : 'A4';

        /*
         * Each report declares the orientation its columns actually fit in;
         * the dialog may override it, but the default is never guesswork.
         */
        $orientation = strtolower((string) $request->input('orientation', ''));
        $orientation = array_key_exists($orientation, self::ORIENTATIONS)
            ? $orientation
            : ReportCatalogue::orientation($reportKey);

        $preset = strtolower((string) $request->input('margins', 'normal'));
        $preset = array_key_exists($preset, self::MARGIN_PRESETS) ? $preset : 'normal';

        $margins = self::MARGIN_PRESETS[$preset]['mm'];

        if ($preset === 'custom') {
            foreach (['top', 'right', 'bottom', 'left'] as $edge) {
                $value = $request->input('margin_'.$edge);

                if (is_numeric($value)) {
                    /* Clamped so an unusable page can never be requested. */
                    $margins[$edge] = min(60.0, max(5.0, (float) $value));
                }
            }
        }

        $fontSize = $request->input('font_size');
        $fontSize = is_numeric($fontSize) ? min(14.0, max(6.0, (float) $fontSize)) : null;

        return new self(
            $format,
            $pageSize,
            $orientation,
            $preset,
            $margins,
            self::flag($request, 'include_summary', true),
            self::flag($request, 'include_generated_by', true),
            self::flag($request, 'include_filters', true),
            self::flag($request, 'include_footer', true),
            in_array($format, ['pdf', 'print'], true)
                && self::flag($request, 'repeat_headers', true),
            $fontSize,
        );
    }

    /** The document toggles, in the shape the Blade partials expect. */
    public function contentToggles(): array
    {
        return [
            'summary' => $this->includeSummary,
            'generated_by' => $this->includeGeneratedBy,
            'filters' => $this->includeFilters,
            'footer' => $this->includeFooter,
            'repeat_headers' => $this->repeatHeaders,
        ];
    }

    public function actionLabel(): string
    {
        return self::ACTION_LABELS[$this->format] ?? 'Export';
    }

    /** Margins converted to points, which is what the PDF renderer takes. */
    public function marginsInPoints(): array
    {
        return array_map(
            static fn (float $mm): float => round($mm * 2.83465, 2),
            $this->marginsMm
        );
    }

    /**
     * A checkbox that is absent from a submitted form means "off", so a
     * missing key is only treated as the default when the form was never
     * submitted at all.
     */
    private static function flag(Request $request, string $key, bool $default): bool
    {
        if (! $request->has('options_submitted')) {
            return $default;
        }

        return $request->boolean($key);
    }
}

<?php

namespace App\Reports;

/**
 * One presentation rule for report summary values.
 *
 * Builders remain the authority on the figures themselves. This formatter
 * only decides how those already-derived values are shown in the web preview,
 * PDF, Word and spreadsheet exports.
 */
final class ReportSummaryFormatter
{
    public static function display(string $label, mixed $value): string
    {
        $numeric = self::numeric($value);

        if ($numeric === null) {
            return (string) $value;
        }

        if (self::isCurrencyLabel($label)) {
            return '₱'.number_format($numeric, 2, '.', ',');
        }

        if (abs($numeric - round($numeric)) < 0.0000001) {
            return number_format($numeric, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($numeric, 2, '.', ','), '0'), '.');
    }

    public static function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim(str_replace(['₱', ',', ' '], '', $value));

        return $normalized !== '' && is_numeric($normalized)
            ? (float) $normalized
            : null;
    }

    public static function isCurrencyLabel(string $label): bool
    {
        $label = mb_strtolower(trim($label));

        foreach ([
            'amount',
            'assessed',
            'verified payment',
            'outstanding balance',
            'remaining balance',
            'total paid',
            'charge',
            'fee',
            'cost',
        ] as $needle) {
            if (str_contains($label, $needle)) {
                return true;
            }
        }

        return false;
    }
}

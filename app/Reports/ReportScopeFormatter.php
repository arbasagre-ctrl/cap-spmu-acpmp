<?php

namespace App\Reports;

/**
 * Presentation-only formatting for selected report scope values.
 *
 * Report builders continue to own filtering. This class only converts the
 * already-authorized applied_filters metadata into formal document rows so
 * web preview, PDF/print, Word and Excel describe the exact same scope under
 * one "Report Scope" line of values, instead of a per-filter label list.
 */
final class ReportScopeFormatter
{
    /**
     * @param  array<string, mixed>  $appliedFilters
     * @return list<array{label:string, value:string}>
     */
    public static function rows(array $appliedFilters): array
    {
        $rows = [];

        foreach ($appliedFilters as $label => $value) {
            $label = trim((string) $label);
            $value = trim((string) $value);

            if ($label === '' || $value === '') {
                continue;
            }

            /*
             * The picker intentionally shows "Name · email" to help staff
             * distinguish people with similar names. Formal reports only need
             * the borrower's name; the email remains a UI search aid.
             */
            if (strcasecmp($label, 'Borrower') === 0) {
                $value = self::borrowerName($value);
            }

            $rows[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    private static function borrowerName(string $value): string
    {
        $parts = preg_split('/\s*[·•]\s*/u', $value, 2);
        $name = trim((string) ($parts[0] ?? ''));

        return $name !== '' ? $name : $value;
    }
}

<?php

namespace App\Reports;

use App\Models\AcademicPeriod;
use App\Support\OrganizationalStructure;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * A validated filter set for one generated report.
 *
 * Filters arrive from the query string, so nothing here trusts the submitted
 * value: every filter is checked against the option list the catalogue
 * declares for that report, and anything unrecognised is dropped rather than
 * passed into a query. A dropped filter is reported through `rejected()` so
 * the builder can tell the user their filter was ignored instead of quietly
 * showing unfiltered records.
 *
 * The reporting period is resolved by ReportingPeriodService, the same service
 * Analytics uses, so "This Semester" cannot mean two different date ranges on
 * the two pages.
 */
final class ReportFilters
{
    /**
     * @param  array<string, string>  $values      validated filter values
     * @param  array<string, string>  $rejected    filter key => submitted value that failed validation
     */
    private function __construct(
        public readonly string $reportKey,
        public readonly CarbonInterface $from,
        public readonly CarbonInterface $to,
        public readonly string $periodSelection,
        public readonly ?AcademicPeriod $selectedAcademicPeriod,
        private readonly array $values,
        private readonly array $rejected,
    ) {}

    /**
     * Build the filter set for a report from the incoming request.
     *
     * @param  array<string, string>  $submitted  raw filter input, filter key => value
     */
    public static function fromRequest(
        Request $request,
        string $reportKey,
        CarbonInterface $from,
        CarbonInterface $to,
        string $periodSelection,
        ?AcademicPeriod $selectedAcademicPeriod = null
    ): self {
        $reportKey = ReportCatalogue::resolveKey($reportKey);
        $applicable = ReportCatalogue::filtersFor($reportKey);

        $values = [];
        $rejected = [];

        /*
         * Division is resolved first because Unit's option list depends on it.
         * A unit is only accepted when it actually belongs to the division
         * that was chosen alongside it.
         */
        $division = null;

        if (array_key_exists('division', $applicable)) {
            $submittedDivision = self::clean($request->input('division'));

            if ($submittedDivision !== null) {
                if (in_array($submittedDivision, OrganizationalStructure::divisionCodes(), true)) {
                    $division = $submittedDivision;
                    $values['division'] = $submittedDivision;
                } else {
                    $rejected['division'] = $submittedDivision;
                }
            }
        }

        foreach ($applicable as $key => $definition) {
            if ($key === 'division') {
                continue;
            }

            $submitted = self::clean($request->input($key));

            if ($submitted === null) {
                continue;
            }

            $options = self::optionsFor($definition, $division);

            if (array_key_exists($submitted, $options)) {
                $values[$key] = $submitted;

                continue;
            }

            $rejected[$key] = $submitted;
        }

        return new self(
            $reportKey,
            $from,
            $to,
            $periodSelection,
            $selectedAcademicPeriod,
            $values,
            $rejected
        );
    }

    /**
     * Resolve a filter's option list, passing the chosen division to
     * dependent filters so Unit can narrow to that division.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, string>
     */
    public static function optionsFor(array $definition, ?string $division = null): array
    {
        $options = $definition['options'] ?? [];

        if (is_callable($options)) {
            return isset($definition['depends_on'])
                ? $options($division)
                : $options();
        }

        return $options;
    }

    /** A validated filter value, or null when the filter is not applied. */
    public function get(string $key): ?string
    {
        return $this->values[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Filters that were submitted but did not survive validation.
     *
     * @return array<string, string>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /**
     * The applied filters as display text, for the report metadata block.
     *
     * @return array<string, string>  filter label => option label
     */
    public function describe(): array
    {
        $definitions = ReportCatalogue::filtersFor($this->reportKey);
        $division = $this->get('division');
        $described = [];

        foreach ($this->values as $key => $value) {
            if (! isset($definitions[$key])) {
                continue;
            }

            $options = self::optionsFor($definitions[$key], $division);

            $described[$definitions[$key]['label']] = $options[$value] ?? $value;
        }

        return $described;
    }

    /** Query-string parameters that reproduce this exact report. */
    public function toQuery(): array
    {
        return array_merge(
            [
                'report' => $this->reportKey,
                'academic_period' => $this->periodSelection,
            ],
            $this->values
        );
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return ($value === '' || strtolower($value) === 'all') ? null : $value;
    }
}

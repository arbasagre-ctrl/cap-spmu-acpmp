<?php

namespace App\Reports;

/**
 * Contract for a formal report.
 *
 * One builder owns one report's query and its column shape. Because screen,
 * CSV and print all consume the returned dataset, a builder is the only place
 * a report's records are ever produced.
 */
interface ReportBuilder
{
    public function build(ReportFilters $filters): ReportDataset;
}

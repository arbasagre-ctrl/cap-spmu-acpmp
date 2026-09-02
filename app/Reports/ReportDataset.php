<?php

namespace App\Reports;

use Illuminate\Support\Collection;

/**
 * One generated report, normalized.
 *
 * Screen, CSV and print all render from this object and never re-query. That
 * is the whole point of it: the same filters must produce the same records in
 * all three outputs, so there is exactly one query path per report and the
 * formats differ only in presentation.
 *
 * `rows` are already-formatted scalar values keyed by column key. A row may
 * additionally carry a `_link` entry, which the screen renders as the row
 * action and which CSV and print ignore; it is presentation, not a record
 * field, so it never changes what the three outputs contain.
 */
final class ReportDataset
{
    /**
     * @param  list<array{key:string, label:string, align?:string}>  $columns
     * @param  Collection<int, array<string, mixed>>                 $rows
     * @param  array<string, mixed>                                  $summary
     * @param  array<string, mixed>                                  $meta
     */
    public function __construct(
        public readonly string $reportKey,
        public readonly string $label,
        public readonly array $columns,
        public readonly Collection $rows,
        public readonly array $summary = [],
        public readonly array $meta = [],
        public readonly ?string $notice = null,
    ) {}

    public function count(): int
    {
        return $this->rows->count();
    }

    public function isEmpty(): bool
    {
        return $this->rows->isEmpty();
    }

    /** Column keys in order, used for CSV headers and print columns. */
    public function columnKeys(): array
    {
        return array_map(static fn (array $column): string => $column['key'], $this->columns);
    }

    /** Column labels in order. */
    public function columnLabels(): array
    {
        return array_map(static fn (array $column): string => $column['label'], $this->columns);
    }

    /**
     * The record values of one row, in column order, with presentation-only
     * keys stripped. This is what CSV writes and what print renders, so the
     * two can never drift from the screen table.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public function values(array $row): array
    {
        return array_map(
            static fn (string $key): string => (string) ($row[$key] ?? ''),
            $this->columnKeys()
        );
    }

    /**
     * Every row reduced to its record values, in column order.
     *
     * @return list<list<string>>
     */
    public function records(): array
    {
        return $this->rows
            ->map(fn (array $row): array => $this->values($row))
            ->values()
            ->all();
    }

    /** Merge additional metadata, returning a new dataset. */
    public function withMeta(array $meta): self
    {
        return new self(
            $this->reportKey,
            $this->label,
            $this->columns,
            $this->rows,
            $this->summary,
            array_merge($this->meta, $meta),
            $this->notice
        );
    }
}

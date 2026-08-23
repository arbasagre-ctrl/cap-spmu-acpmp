@extends('layouts.app', ['title' => 'Reports & Analytics'])

@section('content')

@php
    $periodLabel = $from->isSameDay($to)
        ? $from->format('d M Y')
        : $from->format('d M Y').' – '.$to->format('d M Y');

    $formatSeconds = function (int $seconds): string {
        if ($seconds <= 0) {
            return '0m';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return trim(($hours > 0 ? $hours.'h ' : '').$minutes.'m');
    };
@endphp

<style>
    .reports-detail-page {
        --detail-line: var(--border, #d7e0ea);
        --detail-muted: var(--text-muted, #64748b);
        --detail-ink: var(--text, #18324a);
        --detail-soft: var(--surface-subtle, #f7f9fc);
        display: grid;
        gap: 16px;
    }

    .reports-detail-heading-actions,
    .reports-module-row,
    .report-output-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .report-generator-card {
        padding: 16px;
    }

    .report-generator-grid {
        display: grid;
        grid-template-columns:
            minmax(220px, 1fr)
            minmax(300px, 1.25fr)
            minmax(150px, .55fr)
            minmax(150px, .55fr)
            minmax(110px, auto);
        gap: 12px;
        align-items: end;
    }

    .report-generator-grid label {
        min-width: 0;
        margin: 0;
    }

    .report-generate-button {
        min-height: 42px;
        align-self: end;
        white-space: nowrap;
    }

    .report-period-helper {
        margin: 10px 0 0;
        color: var(--detail-muted);
        font-size: 11px;
        line-height: 1.45;
    }

    .report-generator-grid input,
    .report-generator-grid select {
        width: 100%;
        margin-top: 7px;
    }

    .report-description,
    .report-note {
        color: var(--detail-muted);
        font-size: 12px;
        line-height: 1.45;
    }

    .report-description {
        margin: 10px 0 0;
    }

    .report-note {
        margin: 10px 0 0;
        font-size: 11px;
    }

    .report-summary-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 12px;
    }

    .report-summary-item {
        min-width: 0;
        padding: 12px;
        border: 1px solid var(--detail-line);
        border-radius: 10px;
        background: var(--surface, #fff);
    }

    .report-summary-item strong {
        display: block;
        color: var(--detail-ink);
        font-size: 22px;
        line-height: 1.15;
        overflow-wrap: anywhere;
    }

    .report-summary-item span {
        display: block;
        margin-top: 5px;
        color: var(--detail-muted);
        font-size: 10px;
        font-weight: 700;
    }

    .report-output-card {
        overflow: hidden;
    }

    .report-output-header {
        padding: 16px 18px;
        border-bottom: 1px solid var(--detail-line);
    }

    .report-output-header h2 {
        margin: 2px 0 4px;
    }

    .report-output-header p {
        margin: 0;
        color: var(--detail-muted);
        font-size: 12px;
    }

    .report-output-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }

    .report-output-body {
        padding: 16px 18px 18px;
    }

    .report-table-scroll {
        overflow-x: auto;
    }

    .report-table {
        min-width: 900px;
    }

    .report-table .numeric {
        text-align: right;
    }

    .report-section-subheading {
        margin: 18px 0 8px;
        color: var(--detail-ink);
        font-size: 14px;
    }

    @media (max-width: 1200px) {
        .report-summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }

    @media (max-width: 1050px) {
        .report-generator-grid {
            grid-template-columns: 1fr 1fr;
        }
    }

    @media (max-width: 700px) {
        .report-summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 620px) {
        .report-generator-grid,
        .report-summary-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 480px) {
        .report-summary-grid {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        .reports-detail-heading-actions,
        .reports-module-tabs,
        .report-generator-card,
        .report-output-actions {
            display: none !important;
        }
    }
</style>

<section class="page-heading">
    <div>
        <p class="eyebrow">Formal reporting</p>
        <h1>Reports &amp; Analytics</h1>
        <p>
            Generate detailed operational reports for review, evidence, printing, and CSV export.
        </p>
    </div>

    <div class="reports-detail-heading-actions">
        @if(auth()->user()->hasRole('SPMU') || auth()->user()->hasRole('ICTU'))
            <a class="button secondary ui-pressable" href="{{ route('reports.audit') }}">
                <x-icon name="reports" size="16" />
                Audit Trail
            </a>

            <a class="button secondary ui-pressable" href="{{ route('reports.notifications') }}">
                <x-icon name="notifications" size="16" />
                Delivery
            </a>
        @endif
    </div>
</section>

<div class="reports-detail-page">

    <section class="content-area">
        <div class="reports-module-row">
            @include('reports.partials.module-tabs')

            <span class="meta">Reporting period: {{ $periodLabel }}</span>
        </div>

        <form method="get" class="card report-generator-card top-gap">
            <input type="hidden" name="tab" value="reports">

            <div class="report-generator-grid">
                <label>
                    Report Type
                    <select name="report">
                        @foreach($reportTypes as $key => $meta)
                            <option value="{{ $key }}" @selected($selectedReport === $key)>
                                {{ $meta['label'] }}
                            </option>
                        @endforeach
                    </select>
                </label>

                @include(
                    'reports.partials.academic-period-filter',
                    [
                        'periodSelectId' => 'reports-academic-period',
                        'showPeriodContext' => false,
                    ]
                )

                <button
                    class="button primary ui-pressable report-generate-button"
                    type="submit"
                >
                    Generate
                </button>
            </div>

            <p class="report-period-helper">
                Dates follow the selected academic period.
                Choose <strong>Custom Date Range</strong> to use different dates.
            </p>

            <p class="report-description">{{ $selectedReportMeta['description'] }}</p>
        </form>
    </section>

    @if(!empty($reportSummary))
        <section class="report-summary-grid">
            @foreach($reportSummary as $label => $value)
                <article class="report-summary-item">
                    <strong>
                        @if($selectedReport === 'review-turnaround' && $label === 'Average review turnaround')
                            {{ $formatSeconds((int) $value) }}
                        @elseif($selectedReport === 'compliance' && $label === 'Return compliance')
                            {{ number_format((float) $value, 1) }}%
                        @else
                            {{ is_numeric($value) ? number_format((float) $value, 0) : $value }}
                        @endif
                    </strong>

                    <span>{{ $label }}</span>
                </article>
            @endforeach
        </section>
    @endif

    <section class="content-area">
        <article class="card report-output-card">
            <div class="report-output-header">
                <div>
                    <p class="eyebrow">Generated report</p>
                    <h2>{{ $selectedReportMeta['label'] }}</h2>
                    <p>{{ $periodLabel }}</p>
                </div>

                <div class="report-output-actions">
                    @if($exportType)
                        <a
                            class="button secondary ui-pressable"
                            href="{{ route('reports.export', [
                                'type' => $exportType,
                                'from' => $from->toDateString(),
                                'to' => $to->toDateString(),
                            ]) }}"
                        >
                            <x-icon name="reports" size="16" />
                            Export CSV
                        </a>
                    @endif

                    <button
                        class="button secondary ui-pressable"
                        type="button"
                        onclick="window.print()"
                    >
                        <x-icon name="printer" size="16" />
                        Print
                    </button>
                </div>
            </div>

            <div class="report-output-body">

                @if(in_array($selectedReport, ['borrowing', 'requests'], true))
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Request No.</th>
                                    <th>Borrower</th>
                                    <th>Event / Purpose</th>
                                    <th>Schedule</th>
                                    <th>Expected Return</th>
                                    <th>Status</th>
                                    <th>Created</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    @php $version = $row->currentVersion; @endphp
                                    <tr>
                                        <td><strong>{{ $row->request_no }}</strong></td>
                                        <td>{{ $row->borrower?->full_name }}</td>
                                        <td>{{ $version?->event_details ?: $version?->purpose_event ?: '—' }}</td>
                                        <td>
                                            {{
                                                optional($version?->schedule_date ?: $version?->needed_from)
                                                    ->format('d M Y') ?: '—'
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                optional($version?->return_date ?: $version?->return_due_at)
                                                    ->format('d M Y') ?: '—'
                                            }}
                                        </td>
                                        <td>
                                            <x-status-badge
                                                :status="$row->report_display_status"
                                                :label="$row->report_display_label"
                                            />
                                        </td>
                                        <td><x-date :value="$row->created_at" with-time /></td>
                                        <td>
                                            <a class="table-action ui-pressable" href="{{ route('requests.show', $row) }}">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="empty-state">No request records for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <p class="report-note">
                        <strong>Status rule:</strong>
                        before physical custody, this report uses the request workflow status.
                        Once custody exists, the custody lifecycle becomes authoritative:
                        Active = Released / On Custody,
                        Return Processing = Return Processing,
                        and Closed = Completed.
                        This keeps the report consistent with Request Records and Custody / Return.
                    </p>

                @elseif($selectedReport === 'review-turnaround')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Request No.</th>
                                    <th>Borrower</th>
                                    <th>Received by SPMU</th>
                                    <th>Decision Time</th>
                                    <th>Turnaround</th>
                                    <th>Result</th>
                                    <th>Remarks</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td><strong>{{ $row->request_no }}</strong></td>
                                        <td>{{ $row->borrower_name }}</td>
                                        <td>
                                            <x-date :value="\Carbon\Carbon::parse($row->received_at)" with-time />
                                        </td>
                                        <td>
                                            <x-date :value="\Carbon\Carbon::parse($row->decided_at)" with-time />
                                        </td>
                                        <td><strong>{{ $formatSeconds((int) $row->turnaround_seconds) }}</strong></td>
                                        <td><x-status-badge :status="$row->decision" /></td>
                                        <td>{{ $row->remarks ?: '—' }}</td>
                                        <td>
                                            <a
                                                class="table-action ui-pressable"
                                                href="{{ route('requests.show', $row->request_id) }}"
                                            >
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="empty-state">No completed SPMU reviews for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <p class="report-note">
                        Average Review Turnaround = average time from SPMU received_at to decided_at.
                        Approved, rejected, and returned-for-revision decisions are included.
                    </p>

                @elseif($selectedReport === 'custody')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Custody No.</th>
                                    <th>Request No.</th>
                                    <th>Borrower</th>
                                    <th>Released</th>
                                    <th>Expected Return</th>
                                    <th>Closed</th>
                                    <th>Status</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td><strong>{{ $row->custody_no }}</strong></td>
                                        <td>{{ $row->request?->request_no }}</td>
                                        <td>{{ $row->borrower?->full_name }}</td>
                                        <td>
                                            @if($row->released_at)
                                                <x-date :value="$row->released_at" with-time />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td><x-date :value="$row->due_at" with-time /></td>
                                        <td>
                                            @if($row->closed_at)
                                                <x-date :value="$row->closed_at" with-time />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td><x-status-badge :status="$row->status" /></td>
                                        <td>
                                            <a class="table-action ui-pressable" href="{{ route('custody.show', $row) }}">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="empty-state">No custody activity for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                @elseif($selectedReport === 'inventory')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Category</th>
                                    <th>Unit</th>
                                    <th class="numeric">Total</th>
                                    <th class="numeric">Physical Available</th>
                                    <th class="numeric">Allocated</th>
                                    <th class="numeric">On Custody</th>
                                    <th class="numeric">Laundry</th>
                                    <th class="numeric">Incident / Unavailable</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $item)
                                    @php
                                        $balance = $item->report_balance;
                                        $incidentUnavailable =
                                            (float) ($balance['damaged_maintenance'] ?? 0)
                                            + (float) ($balance['lost'] ?? 0)
                                            + (float) ($balance['stolen'] ?? 0)
                                            + (float) ($balance['destroyed'] ?? 0)
                                            + (float) ($balance['condemned'] ?? 0);
                                    @endphp
                                    <tr>
                                        <td><strong>{{ $item->unique_description }}</strong></td>
                                        <td>{{ $item->category?->category_name }}</td>
                                        <td>{{ $item->unit?->unit_name }}</td>
                                        <td class="numeric">{{ ($balance['total'] ?? 0) + 0 }}</td>
                                        <td class="numeric">
                                            {{ ($balance['current_available'] ?? $balance['available'] ?? 0) + 0 }}
                                        </td>
                                        <td class="numeric">
                                            {{ ($balance['allocated'] ?? $balance['reserved'] ?? 0) + 0 }}
                                        </td>
                                        <td class="numeric">{{ ($balance['borrowed'] ?? 0) + 0 }}</td>
                                        <td class="numeric">{{ ($balance['laundry'] ?? 0) + 0 }}</td>
                                        <td class="numeric">{{ $incidentUnavailable + 0 }}</td>
                                        <td>
                                            <a class="table-action ui-pressable" href="{{ route('inventory.show', $item) }}">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="10" class="empty-state">No active inventory items.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <p class="report-note">
                        Inventory State is a current physical snapshot, not a historical stock reconstruction.
                    </p>

                @elseif($selectedReport === 'utilization')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Item</th>
                                    <th class="numeric">Released Quantity</th>
                                    <th>Utilization State</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td><strong>{{ $row->unique_description }}</strong></td>
                                        <td class="numeric">{{ $row->used_quantity + 0 }}</td>
                                        <td>{{ (float) $row->used_quantity > 0 ? 'Utilized' : 'No utilization' }}</td>
                                        <td>
                                            <a class="table-action ui-pressable" href="{{ route('inventory.show', $row->id) }}">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="empty-state">No active inventory items.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <p class="report-note">
                        The ranking is automatic across all active inventory items.
                        The Analytics page shows only the top 5 and least 5 previews.
                    </p>

                @elseif($selectedReport === 'overdue')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Custody No.</th>
                                    <th>Borrower</th>
                                    <th>Expected Return</th>
                                    <th>Overdue Started</th>
                                    <th>Offense Level</th>
                                    <th>Status</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td><strong>{{ $row->custody?->custody_no }}</strong></td>
                                        <td>{{ $row->borrower?->full_name }}</td>
                                        <td><x-date :value="$row->custody?->due_at" with-time /></td>
                                        <td>
                                            @if($row->overdue_started_at)
                                                <x-date :value="$row->overdue_started_at" with-time />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>{{ $row->offense_level }}</td>
                                        <td><x-status-badge :status="$row->status" /></td>
                                        <td>
                                            @if($row->custody)
                                                <a class="table-action ui-pressable" href="{{ route('custody.show', $row->custody) }}">
                                                    View
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="empty-state">No overdue cases for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                @elseif($selectedReport === 'accountability')
                    <h3 class="report-section-subheading">Property incidents</h3>

                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Incident No.</th>
                                    <th>Custody</th>
                                    <th>Borrower</th>
                                    <th>Type</th>
                                    <th>Affected Qty</th>
                                    <th>Reported</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td><strong>{{ $row->incident_no }}</strong></td>
                                        <td>{{ $row->custody?->custody_no }}</td>
                                        <td>{{ $row->borrower?->full_name }}</td>
                                        <td>{{ str($row->incident_type)->replace('_', ' ')->title() }}</td>
                                        <td>{{ $row->lines->sum('quantity') + 0 }}</td>
                                        <td><x-date :value="$row->reported_at" with-time /></td>
                                        <td><x-status-badge :status="$row->status" /></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="empty-state">No property incidents for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h3 class="report-section-subheading">Confirmed borrower violations</h3>

                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Borrower</th>
                                    <th>Custody</th>
                                    <th>Reasons</th>
                                    <th>Detected</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($secondaryRows as $row)
                                    <tr>
                                        <td>{{ $row->borrower?->full_name }}</td>
                                        <td>{{ $row->custody?->custody_no }}</td>
                                        <td>{{ str($row->violation_code)->replace('_', ' ')->title() }}</td>
                                        <td><x-date :value="$row->detected_at" with-time /></td>
                                        <td><x-status-badge :status="$row->status" /></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="empty-state">No confirmed violations for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                @elseif($selectedReport === 'compliance')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Custody No.</th>
                                    <th>Request No.</th>
                                    <th>Borrower</th>
                                    <th>Released</th>
                                    <th>Expected Return</th>
                                    <th>Closed</th>
                                    <th>State</th>
                                    <th><span class="visually-hidden">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td><strong>{{ $row->custody_no }}</strong></td>
                                        <td>{{ $row->request?->request_no }}</td>
                                        <td>{{ $row->borrower?->full_name }}</td>
                                        <td><x-date :value="$row->released_at" with-time /></td>
                                        <td><x-date :value="$row->due_at" with-time /></td>
                                        <td>
                                            @if($row->closed_at)
                                                <x-date :value="$row->closed_at" with-time />
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            <x-status-badge
                                                :status="$row->status === 'CLOSED' ? 'COMPLETED' : 'PENDING'"
                                                :label="$row->status === 'CLOSED' ? 'Closed' : 'Still open'"
                                            />
                                        </td>
                                        <td>
                                            <a class="table-action ui-pressable" href="{{ route('custody.show', $row) }}">
                                                View
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="empty-state">No released custody transactions for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                @elseif($selectedReport === 'borrowers')
                    <div class="report-table-scroll">
                        <table class="report-table">
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Borrower</th>
                                    <th class="numeric">Requests</th>
                                    <th class="numeric">Late Return Cases</th>
                                    <th class="numeric">Confirmed Violations</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reportRows as $row)
                                    <tr>
                                        <td>{{ $loop->iteration }}</td>
                                        <td><strong>{{ $row->full_name }}</strong></td>
                                        <td class="numeric">{{ $row->request_count }}</td>
                                        <td class="numeric">{{ $row->late_count }}</td>
                                        <td class="numeric">{{ $row->violation_count }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="empty-state">No borrower activity for this period.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

            </div>
        </article>
    </section>

</div>

@endsection

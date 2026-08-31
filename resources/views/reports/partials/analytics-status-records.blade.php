@if($statusFocus)
        <section class="content-area request-status-results" id="request-status-records">
            <article class="card">
                <div class="request-status-results-header">
                    <div>
                        <p class="eyebrow">Matching requests</p>
                        <h3>{{ $statusFocusLabels[$statusFocus] ?? 'Request Records' }}</h3>
                        <p>
                            {{ number_format($requestStatusRecords->total()) }} record(s) in {{ strtolower($reportingPeriodLabel) }} scope
                            · {{ $periodLabel }}
                        </p>
                    </div>
                    <a class="button secondary small ui-pressable" href="{{ $reportUrl('requests', $statusFocus) }}">
                        Open full request report
                    </a>
                </div>

                @if($requestStatusRecords->isNotEmpty())
                    <div class="request-status-table-wrap">
                        <table class="request-status-table">
                            <thead>
                                <tr>
                                    <th>Request</th>
                                    <th>Borrower</th>
                                    <th>Purpose / Location</th>
                                    <th>Borrowing Period</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($requestStatusRecords as $record)
                                    @php
                                        [$recordStatus, $recordStatusLabel] = $recordReportStatus($record);
                                        $recordVersion = $record->currentVersion;
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $record->request_no }}</strong>
                                            <small>Created {{ optional($record->created_at)->format('d M Y') }}</small>
                                        </td>
                                        <td>
                                            {{ $record->borrower?->full_name ?? 'Unknown borrower' }}
                                            <small>{{ $record->borrower?->department ?: '—' }}</small>
                                        </td>
                                        <td>
                                            {{ $recordVersion?->purpose_event ?: 'No purpose recorded' }}
                                            <small>{{ $recordVersion?->location ?: 'No location recorded' }}</small>
                                        </td>
                                        <td>
                                            {{ optional($recordVersion?->schedule_date)->format('d M Y') ?: '—' }}
                                            <small>to {{ optional($recordVersion?->return_date)->format('d M Y') ?: '—' }}</small>
                                        </td>
                                        <td>
                                            <x-status-badge
                                                :status="$recordStatus"
                                                :label="$recordStatusLabel ?: str($recordStatus)->replace('_', ' ')->title()"
                                            />
                                        </td>
                                        <td>
                                            <a class="table-action ui-pressable" href="{{ route('requests.show', $record) }}">
                                                View details
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="request-status-results-footer">
                        <span class="meta">
                            Showing {{ $requestStatusRecords->firstItem() }}–{{ $requestStatusRecords->lastItem() }}
                            of {{ $requestStatusRecords->total() }}
                        </span>
                        @if($requestStatusRecords->hasPages())
                            {{ $requestStatusRecords->onEachSide(1)->links() }}
                        @endif
                    </div>
                @else
                    <div class="request-status-empty">
                        <strong>No matching request records.</strong>
                        <div>There are no {{ strtolower($statusFocusLabels[$statusFocus] ?? 'matching') }} records for {{ $periodLabel }}.</div>
                    </div>
                @endif
            </article>
        </section>
    @endif

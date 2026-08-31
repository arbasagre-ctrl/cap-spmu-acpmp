@if($selectedReport === 'borrowing')
    @php
        $primarySummary = [
            'Total requests' => ['label' => 'Total Requests', 'copy' => 'Requests created in the selected period', 'icon' => 'requests', 'tone' => 'blue'],
            'Ready for Release' => ['label' => 'Ready for Release', 'copy' => 'Approved but not yet released', 'icon' => 'clock', 'tone' => 'amber'],
            'Released / On Custody' => ['label' => 'Released / On Custody', 'copy' => 'Currently released or under custody', 'icon' => 'success', 'tone' => 'green'],
        ];
        $otherSummary = collect($reportSummary)->except(array_keys($primarySummary))->filter(fn ($value) => is_numeric($value) && (float) $value > 0);
    @endphp
    <section class="report-summary-grid report-primary-summaries" aria-label="Borrowing activity summary">
        @foreach($primarySummary as $key => $metric)
            <article class="card report-summary-item">
                <span class="reporting-icon tone-{{ $metric['tone'] }}"><x-icon :name="$metric['icon']" size="25" /></span>
                <div><strong>{{ number_format($reportSummary[$key] ?? 0) }}</strong><h2>{{ $metric['label'] }}</h2><p>{{ $metric['copy'] }}</p></div>
            </article>
        @endforeach
    </section>
    @if($otherSummary->isNotEmpty())
        <div class="report-additional-summary" aria-label="Other request states">
            @foreach($otherSummary as $label => $value)<span>{{ $label }} <strong>{{ number_format($value) }}</strong></span>@endforeach
        </div>
    @endif
@elseif(!empty($reportSummary))
    <section class="report-summary-grid" aria-label="Report summary">
        @foreach($reportSummary as $label => $value)
            <article class="card report-summary-item">
                <div>
                    <strong>
                        @if($selectedReport === 'review-turnaround' && $label === 'Average review turnaround')
                            {{ $formatSeconds((int) $value) }}
                        @elseif($selectedReport === 'compliance' && $label === 'Return compliance')
                            {{ number_format((float) $value, 1) }}%
                        @else
                            {{ is_numeric($value) ? number_format((float) $value, 0) : $value }}
                        @endif
                    </strong>
                    <h2>{{ $label }}</h2>
                </div>
            </article>
        @endforeach
    </section>
@endif

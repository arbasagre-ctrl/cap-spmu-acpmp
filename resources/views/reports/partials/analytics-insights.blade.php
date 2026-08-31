<article class="card analytics-insights">
    <h2>Operational insights</h2>
    <div class="analytics-insights-grid">
        <section class="analytics-insight">
            <div class="analytics-insight-heading"><span class="reporting-icon tone-blue"><x-icon name="users" size="16" /></span><div><h3>Borrower activity</h3><p>Most frequent borrowers</p></div></div>
            <ol class="analytics-borrowers">
                @forelse($mostFrequentBorrowers->take(3) as $row)
                    <li><span>{{ $row->full_name }}</span><strong>{{ $row->borrowing_count }} {{ $row->borrowing_count == 1 ? 'request' : 'requests' }}</strong></li>
                @empty
                    <li class="analytics-ranking-empty">No borrower activity for this period.</li>
                @endforelse
            </ol>
            <a class="analytics-link" href="{{ $reportUrl('borrowers') }}">View borrower report <span aria-hidden="true">→</span></a>
        </section>
        <section class="analytics-insight">
            <div class="analytics-insight-heading"><span class="reporting-icon tone-red"><x-icon name="accountability" size="16" /></span><div><h3>Return &amp; accountability</h3><p>Late returns and violations</p></div></div>
            <div class="analytics-insight-content">
                @foreach($borrowersWithMostLateReturns->take(2) as $row)
                    <p>{{ $row->full_name }} <strong>· {{ $row->late_return_count }} late returns</strong></p>
                @endforeach
                @foreach($borrowersWithMostViolations->take(2) as $row)
                    <p>{{ $row->full_name }} <strong>· {{ $row->violation_count }} confirmed violations</strong></p>
                @endforeach
                @if($borrowersWithMostLateReturns->isEmpty() && $borrowersWithMostViolations->isEmpty())
                    <p>No late-return or confirmed-violation activity.</p>
                @endif
            </div>
            <a class="analytics-link" href="{{ $reportUrl('accountability') }}">View accountability report <span aria-hidden="true">→</span></a>
        </section>
        <section class="analytics-insight">
            <div class="analytics-insight-heading"><span class="reporting-icon tone-purple"><x-icon name="requests" size="16" /></span><div><h3>Asset incidents</h3><p>Recorded condition incidents</p></div></div>
            <div class="analytics-insight-content">
                @forelse($assetConditionTrends->take(2) as $row)
                    <p>{{ $row->unique_description }} <strong>· {{ $row->affected_quantity + 0 }} {{ str($row->observed_condition)->replace('_', ' ')->lower() }}</strong></p>
                @empty
                    <p>No asset condition incidents recorded for this period.</p>
                @endforelse
            </div>
            <a class="analytics-link" href="{{ $reportUrl('accountability') }}">View incident report <span aria-hidden="true">→</span></a>
        </section>
        <section class="analytics-insight">
            <div class="analytics-insight-heading"><span class="reporting-icon tone-green"><x-icon name="inventory" size="16" /></span><div><h3>Inventory health (snapshot)</h3><p>Current operational inventory</p></div></div>
            <dl class="analytics-inventory-snapshot">
                @foreach(['physical_available' => 'Available', 'allocated' => 'Allocated', 'on_custody' => 'On Custody', 'incident_unavailable' => 'Loss/Incident'] as $key => $label)
                    <div><dt>{{ $label }}</dt><dd>{{ $inventoryHealth[$key] + 0 }}</dd></div>
                @endforeach
            </dl>
            @if(($inventoryHealth['laundry'] ?? 0) > 0)<p class="analytics-laundry-count">In laundry: {{ $inventoryHealth['laundry'] + 0 }}</p>@endif
            <a class="analytics-link" href="{{ $reportUrl('inventory') }}">Detailed inventory report <span aria-hidden="true">→</span></a>
        </section>
    </div>
</article>

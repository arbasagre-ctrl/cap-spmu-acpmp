@php
    $custody = $gatePass->custody;
    $borrower = $custody?->borrower;
    $requestRecord = $custody?->request;
    $version = $requestRecord?->currentVersion;
    $destination = $gatePass->destination ?: ($version?->location ?: '—');
    $statusLabel = $gatePassStatusLabels[$gatePass->status] ?? str($gatePass->status)->replace('_', ' ')->title();
    $statusTone = $gatePassStatusTones[$gatePass->status] ?? 'neutral';
    $releasedAt = $gatePass->guard_signed_at ?: $custody?->released_at;
    $hasFinalDocument = in_array($gatePass->status, ['READY_FOR_PRINTING', 'VERIFIED'], true) && $gatePass->passDocument;
    $hasMoreActions = $custody || $hasFinalDocument || $gatePass->accomplishedFile;
    $search = implode(' ', [
        $requestRecord?->request_no,
        $custody?->custody_no,
        $borrower?->full_name,
        $borrower?->organizationalUnit?->unit_name,
        $destination,
        $gatePass->purpose,
        $statusLabel,
    ]);
@endphp
<tr data-gate-pass-record data-search="{{ $search }}" data-status="{{ $gatePass->status }}" data-date="{{ $gatePass->updated_at?->timestamp ?? 0 }}">
    <td><a class="gate-pass-request-link" href="{{ route('gate-passes.show', $gatePass) }}">{{ $requestRecord?->request_no ?: 'Gate Pass #'.$gatePass->id }}</a></td>
    <td><strong>{{ $borrower?->full_name ?: '—' }}</strong><small>{{ $borrower?->organizationalUnit?->unit_name ?: '—' }}</small></td>
    <td class="gate-pass-destination">{{ $destination }}</td>
    <td class="gate-pass-release-date">
        @if($releasedAt)
            <time datetime="{{ $releasedAt->toIso8601String() }}">{{ $releasedAt->format('M d, Y') }}</time>
        @else
            <span title="Physical release has not been recorded">—</span>
        @endif
    </td>
    <td><span class="status-badge status-{{ $statusTone }}" title="{{ str($gatePass->status)->replace('_', ' ')->title() }}">{{ $statusLabel }}</span></td>
    <td>
        <div class="gate-pass-row-actions">
            <a class="button secondary small gate-pass-view" href="{{ route('gate-passes.show', $gatePass) }}">View details</a>
            @if($hasMoreActions)
                <details class="gate-pass-more" name="gate-pass-record-actions">
                    <summary aria-label="More actions for {{ $requestRecord?->request_no ?: 'Gate Pass #'.$gatePass->id }}">
                        <svg width="16" height="20" viewBox="0 0 20 24" fill="currentColor" aria-hidden="true"><circle cx="10" cy="5" r="1.5" /><circle cx="10" cy="12" r="1.5" /><circle cx="10" cy="19" r="1.5" /></svg>
                    </summary>
                    <div class="gate-pass-more-links">
                        @if($hasFinalDocument)
                            <a href="{{ route('documents.download', $gatePass->passDocument) }}" target="_blank" rel="noopener">View final Gate Pass</a>
                        @endif
                        @if($gatePass->accomplishedFile)
                            <a href="{{ route('files.show', $gatePass->accomplishedFile, false) }}" target="_blank" rel="noopener">View accomplished scan</a>
                        @endif
                        @if($custody)
                            <a href="{{ route('custody.show', $custody) }}">Open custody record</a>
                        @endif
                    </div>
                </details>
            @endif
        </div>
    </td>
</tr>

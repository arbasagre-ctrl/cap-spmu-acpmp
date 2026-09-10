@extends('layouts.app', ['title' => 'Laundry Operations'])
@section('content')
@php
    $allLinenReturned = $job->lines->isNotEmpty()
        && $job->lines->every(function ($line) {
            $custodyLine = $line->custodyLine;
            return $custodyLine
                && (float) $custodyLine->returned_quantity >= (float) $custodyLine->actual_released_quantity;
        });

    $totalIssued = (int) round($job->lines->sum(fn ($line) => (float) $line->issued_quantity));
    $formArchived = $job->hasVerifiedAccomplishedForm();
    $internalLaundryQuantity = function ($line): int {
        $stored = (int) round((float) ($line->received_quantity ?? 0));

        if ($stored > 0) {
            return $stored;
        }

        return (int) round((float) $line->custodyLine->returnLines
            ->where('disposition_state', 'LAUNDRY')
            ->sum('quantity_received'));
    };
    $totalInternalLaundry = $job->lines->sum(fn ($line) => $internalLaundryQuantity($line));
    $returnEncoded = $allLinenReturned
        || in_array($job->status, ['TURNED_OVER_TO_LAUNDRY', 'LAUNDRY_COMPLETED'], true);

    $expectedReturn = $job->custody?->original_due_at;
    $isLateReturn = $job->worker_received_at && $expectedReturn
        ? $job->worker_received_at->copy()->startOfDay()->gt($expectedReturn->copy()->startOfDay())
        : false;
    $adverseConditions = $job->lines
        ->flatMap(fn ($line) => $line->custodyLine?->returnLines ?? collect())
        ->map(fn ($returnLine) => strtoupper((string) $returnLine->condition_code))
        ->filter(fn ($condition) => $condition !== '' && $condition !== 'FINE')
        ->unique()
        ->values();
    $hasAdverseFinding = $adverseConditions->isNotEmpty();
    $adverseReason = $adverseConditions
        ->map(fn ($condition) => match ($condition) {
            'DAMAGED' => 'Damaged Item',
            'DESTROYED' => 'Destroyed Item',
            'MISSING' => 'Missing Item',
            'LOST' => 'Lost Item',
            'STOLEN' => 'Stolen Item',
            default => ucwords(strtolower(str_replace('_', ' ', $condition))),
        })
        ->implode(' + ');
    $accountabilityReason = collect([
        $isLateReturn ? 'Late Return' : null,
        $hasAdverseFinding ? $adverseReason : null,
    ])->filter()->implode(' + ') ?: null;
@endphp

<div class="laundry-detail">
<section class="page-heading laundry-detail-heading">
    <div>
        <p class="eyebrow">SPMU Action Officer · Laundry</p>
        <h1>{{ $job->custody->custody_no }}</h1>
        <p>{{ $job->custody->borrower->full_name }} · Request {{ $job->custody->request->request_no }}</p>
    </div>
    <a class="button secondary ui-pressable" href="{{ route('custody.return.show', $job->custody) }}#return-summary">Back to Return</a>
</section>

<section class="content-area laundry-detail-tracker">
    <x-laundry-progress-tracker :job="$job" :inspection-complete="$allLinenReturned" />
</section>

<section class="content-area">
    <div class="content-grid two laundry-operation-grid">
        <article class="card laundry-form-card">
            <div class="card-header laundry-detail-card-title">
                <x-icon name="requests" size="27" />
                <h2>Laundry Form</h2>
            </div>

            @if($job->status !== 'LAUNDRY_COMPLETED')
                <p class="laundry-form-description">The same printed form travels with the borrower during custody. On return, Laundry Personnel record <strong>Received by</strong> and the actual receipt date, complete the offline laundry process, then deliver the accomplished form to SPMU. No Laundry portal login is used.</p>
            @endif

            <div class="inline-actions laundry-form-actions">
                @if($job->latestEvidence?->file)
                    <a class="button secondary small ui-pressable" href="{{ route('files.show', $job->latestEvidence->file, false) }}" target="_blank" rel="noopener">View Accomplished Form</a>
                @elseif($job->document)
                    <a class="button secondary ui-pressable" href="{{ route('documents.download', $job->document) }}" target="_blank" rel="noopener">View Laundry Form</a>
                @endif
            </div>

            @if($job->status === 'LAUNDRY_COMPLETED')
                <p class="meta top-gap"><strong>Received by Laundry</strong> is the borrower's actual linen return date.</p>
            @else
                <p class="meta top-gap"><strong>Received by</strong> is the borrower's actual linen return date and controls return timeliness. The later SPMU upload/encoding date does not replace it.</p>
            @endif
        </article>

        <article class="card laundry-linen-card">
            <div class="card-header laundry-detail-card-title"><x-icon name="box" size="27" /><h2>Linen Status</h2></div>
            <dl class="detail-list laundry-linen-facts">
                <dt>Total issued:</dt><dd>{{ $totalIssued }}</dd>
                <dt>Received by Laundry:</dt><dd>{{ $job->worker_received_at?->format('d F Y') ?: 'Pending' }}</dd>

                @if($job->status !== 'LAUNDRY_COMPLETED')
                    <dt>Completed form:</dt><dd>{{ $formArchived ? 'Received by SPMU' : 'Pending from Laundry Personnel' }}</dd>
                    <dt>SPMU return encoding:</dt><dd>{{ $returnEncoded ? 'Complete' : 'Pending' }}</dd>
                @endif

                <dt>Serviceable quantity:</dt><dd>{{ $totalInternalLaundry }}</dd>
                <dt>Availability:</dt>
                <dd>
                    @if($job->status === 'LAUNDRY_COMPLETED')
                        Available
                    @elseif($job->status === 'TURNED_OVER_TO_LAUNDRY')
                        System reconciliation pending
                    @elseif($formArchived)
                        Waiting for SPMU return encoding
                    @else
                        Waiting for completed form
                    @endif
                </dd>
            </dl>
        </article>
    </div>
</section>

@if($job->status === 'FOR_LAUNDRY')
<section class="content-area">
    <article class="card laundry-next-action">
        <x-icon name="requests" size="36" />
        <div>
            @if(! $formArchived)
                <h2>Laundry processing / completed form pending</h2>
                <p>The borrower returns the linen and form to the Laundry Area first. Laundry Personnel record <strong>Received by</strong> and the actual receipt date, finish the laundry process, then deliver the accomplished form to SPMU. Even if SPMU receives it later, the Received by date controls borrower lateness.</p>
            @else
                <h2>Encode the completed Laundry Form in SPMU Return</h2>
                <p>The completed form is already on file. Record the full received quantity as Fine / Good when no issue was reported; otherwise encode the applicable adverse quantity with evidence. Serviceable linen becomes Available automatically after encoding. No second linen inspection is required.</p>
            @endif
        </div>
        <a class="button primary ui-pressable" href="{{ route('custody.return.show', $job->custody) }}#return-primary">Open SPMU Return</a>
    </article>
</section>
@endif

@if($job->status === 'TURNED_OVER_TO_LAUNDRY')
<section class="content-area">
    <article class="card">
        <div class="callout info">
            <strong>Older Laundry record pending automatic reconciliation.</strong>
            <p>No Action Officer action is required. The system reconciliation restores the already-confirmed serviceable quantity to Available inventory.</p>
        </div>
    </article>
</section>
@endif

@if($job->status === 'LAUNDRY_COMPLETED')
<section class="content-area">
    <article class="card">
        <div class="card-header">
            <h2>Completion Summary</h2>
        </div>
        <p><strong>{{ $totalInternalLaundry }} serviceable linen {{ $totalInternalLaundry === 1 ? 'item has' : 'items have' }} been returned to Available inventory.</strong></p>
        <div class="meta top-gap">
            @if($job->worker_received_at)
                <span>Borrower return: {{ $job->worker_received_at->format('d M Y') }}</span>
            @endif
            @if($job->completed_at)
                <span> · Recorded by SPMU: {{ $job->completed_at->format('d M Y, g:i A') }}</span>
            @endif
        </div>

        @if($accountabilityReason)
            <div class="callout warning top-gap">
                <strong>Accountability Required — {{ $accountabilityReason }}</strong>
                <p>This Laundry case is complete and serviceable linen is already Available. The borrower issue is handled separately in Accountability Processing.</p>
                @if($isLateReturn && $expectedReturn && $job->worker_received_at)
                    <p class="meta">Expected return: {{ $expectedReturn->format('d M Y') }} · Actual linen return: {{ $job->worker_received_at->format('d M Y') }}</p>
                @endif
            </div>
        @endif
    </article>
</section>
@endif

</div>
@include('laundry.partials.detail-styles')
@endsection

@php
    $releaseScheduleAttention = ! $hasPickupSchedule || $pickupWindowPassed;
    $releaseScheduleEditorOpen = $releaseScheduleAttention || $errors->has('pickup_at') || $errors->has('pickup_expires_at');
    $releaseCurrentDocuments = $documents->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']);
    $releaseDocumentsReady = $releaseCurrentDocuments->contains('document_type', 'BORROWER_SLIP')
        && (! $hasOffCampusItem || $releaseCurrentDocuments->contains('document_type', 'GATE_PASS'))
        && (! $hasLaundryItem || $releaseCurrentDocuments->contains('document_type', 'LAUNDRY_FORM'));
@endphp

<x-request-progress-tracker :request="$custody->request" :show-current-status="false" :compact="true" :release-view="true" />

<section class="content-grid two release-context-grid">
    <article class="card release-context-card">
        <div class="release-card-title"><x-icon name="calendar" size="21" /><h2>Borrowing Schedule</h2></div>
        <dl class="detail-list release-context-list">
            <dt>Purpose / Event</dt><dd>{{ $version?->purpose_event ?: '—' }}</dd>
            <dt>Schedule Date</dt><dd>{{ $scheduleDate?->format('d F Y') ?: 'Not available' }}</dd>
            <dt>Expected Return Date</dt><dd>{{ $returnDate?->format('d F Y') ?: 'Not available' }}</dd>
            <dt>Borrower</dt><dd>{{ $custody->borrower?->full_name ?: '—' }}</dd>
        </dl>
    </article>
    <article class="card release-context-card">
        <div class="release-card-title"><x-icon name="requests" size="21" /><h2>Release Requirements</h2></div>
        <dl class="detail-list release-context-list">
            <dt>Use Location</dt><dd>{{ $hasOffCampusItem ? 'Off-campus item included' : 'On-campus only' }}</dd>
            <dt>Gate Pass</dt><dd>{{ $hasOffCampusItem ? 'Required before off-campus exit' : 'Not required' }}</dd>
            <dt>Laundry Form</dt><dd>{{ $hasLaundryItem ? 'Required for applicable linen' : 'Not required' }}</dd>
        </dl>
    </article>
</section>

<section class="content-area">
    <article class="card release-approved-card">
        <div class="release-card-title"><x-icon name="inventory" size="21" /><h2>Approved Items</h2></div>
        <div class="table-wrap">
            <table class="release-approved-table">
                <thead><tr><th scope="col">Item</th><th scope="col">Approved Quantity</th><th scope="col">Issued</th><th scope="col">Returned</th></tr></thead>
                <tbody>
                    @forelse($custody->lines as $line)
                        <tr>
                            <td><strong>{{ $line->requestItem->description_snapshot }}</strong><small>{{ $line->requestItem->unit_snapshot }}</small></td>
                            <td>{{ $line->approved_quantity + 0 }}</td><td>{{ $line->actual_released_quantity + 0 }}</td><td>{{ $line->returned_quantity + 0 }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No approved items.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>

<section class="content-area">
    <article class="card release-process-card" aria-labelledby="release-process-title">
        <h2 id="release-process-title">Release Process</h2>
        <ol class="release-process-steps">
            <li class="release-process-step {{ $releaseScheduleAttention ? 'is-current' : 'is-complete' }}">
                <span class="release-step-number" aria-hidden="true">1</span>
                <div class="release-step-heading">
                    <div class="release-step-copy">
                        <h3>Pickup Schedule</h3>
                        @if($hasPickupSchedule)
                            <p class="release-step-schedule"><x-icon name="calendar" size="16" />{{ $custody->scheduled_release_at->format('M j, Y') }} · {{ $custody->scheduled_release_at->format('g:i A') }} – {{ $custody->pickup_expires_at->format('g:i A') }}</p>
                            @if($custody->pickup_scheduled_at)
                                <p class="release-step-notified"><x-icon name="approval" size="16" />Borrower has been notified.</p>
                            @endif
                        @else
                            <p>Set the pickup date and claim window.</p>
                        @endif
                    </div>
                    <div class="release-schedule-status">
                        <span class="release-step-badge {{ $releaseScheduleAttention ? 'is-pending' : 'is-complete' }}">{{ $releaseScheduleAttention ? 'Schedule Required' : 'Scheduled' }}</span>
                        @if($hasPickupSchedule)
                            <button class="link-button release-schedule-edit" type="button" data-release-schedule-edit aria-controls="release-schedule-editor" aria-expanded="{{ $releaseScheduleEditorOpen ? 'true' : 'false' }}"><x-icon name="edit" size="15" />Edit schedule</button>
                        @endif
                    </div>
                    <div class="release-step-actions release-schedule-actions">
                        <button class="icon-button release-step-toggle release-schedule-toggle" type="button" data-release-panel-toggle aria-controls="release-schedule-editor" aria-expanded="{{ $releaseScheduleEditorOpen ? 'true' : 'false' }}" aria-label="Toggle pickup schedule editor" title="Show or hide pickup schedule"><x-icon name="chevron-down" size="18" /></button>
                    </div>
                </div>
                <div class="release-step-panel" id="release-schedule-editor" @if(!$releaseScheduleEditorOpen) hidden @endif>
                    @include('custody.partials.pickup-schedule-form')
                </div>
            </li>

            <li class="release-process-step {{ $preparationComplete ? 'is-complete' : ($hasPickupSchedule ? 'is-current' : 'is-pending') }}" id="item-preparation">
                <span class="release-step-number" aria-hidden="true">2</span>
                <div class="release-step-heading">
                    <div class="release-step-copy"><h3>Item Preparation</h3><p>{{ $preparationComplete ? 'Prepared quantities match the approved quantities.' : ($hasPickupSchedule ? 'Confirm the quantities prepared for release.' : 'Save a pickup schedule before confirming preparation.') }}</p></div>
                    <span class="release-step-badge {{ $preparationComplete ? 'is-complete' : 'is-pending' }}">{{ $preparationComplete ? 'Confirmed' : 'Pending' }}</span>
                    <div class="release-step-actions">
                        @if($hasPickupSchedule)
                            <button class="icon-button release-step-toggle" type="button" data-release-panel-toggle aria-controls="release-preparation-panel" aria-expanded="{{ $preparationComplete ? 'false' : 'true' }}" aria-label="Toggle item preparation details" title="Show or hide item preparation"><x-icon name="chevron-down" size="18" /></button>
                        @endif
                    </div>
                </div>
                @if($hasPickupSchedule)
                    <div class="release-step-panel" id="release-preparation-panel" @if($preparationComplete) hidden @endif>
                        @include('custody.partials.item-preparation-form')
                    </div>
                @endif
            </li>

            <li class="release-process-step {{ $preparationComplete && $hasPickupSchedule && $releaseDocumentsReady ? 'is-complete' : 'is-pending' }}" id="release-actions">
                <span class="release-step-number" aria-hidden="true">3</span>
                <div class="release-step-heading">
                    <div class="release-step-copy"><h3>Release Documents</h3></div>
                    <span class="release-step-badge {{ $preparationComplete && $hasPickupSchedule && $releaseDocumentsReady ? 'is-complete' : 'is-pending' }}">{{ $preparationComplete && $hasPickupSchedule && $releaseDocumentsReady ? 'Ready' : 'Pending' }}</span>
                    <div class="release-step-actions">
                        @if($preparationComplete && $hasPickupSchedule)
                            <button class="icon-button release-step-toggle" type="button" data-release-panel-toggle aria-controls="release-documents-panel" aria-expanded="true" aria-label="Toggle release document references" title="Show or hide release documents"><x-icon name="chevron-down" size="18" /></button>
                        @endif
                    </div>
                </div>
                @if($preparationComplete && $hasPickupSchedule)
                    <div class="release-step-panel release-documents-panel" id="release-documents-panel">
                        @include('custody.partials.release-documents')
                        @if($hasOffCampusItem)
                            <p class="release-step-note">Validate that the borrower presented the approved generated Gate Pass before recording the physical handover.</p>
                        @endif
                    </div>
                @else
                    <p class="release-step-note">Confirm item preparation to review the release documents.</p>
                @endif
            </li>

            <li class="release-process-step {{ $preparationComplete && $hasPickupSchedule ? 'is-current' : 'is-pending' }}" id="physical-release" aria-labelledby="physical-release-title">
                <span class="release-step-number" aria-hidden="true">4</span>
                <div class="release-physical-heading">
                    <h3 id="physical-release-title">Physical Release</h3>
                </div>
                @if($preparationComplete && $hasPickupSchedule)
                    @if($pickupWindowUpcoming)
                        <div class="callout info release-window-notice" id="physical-release-availability">
                            <strong>Physical release is not available yet.</strong>
                            <p>The handover may be recorded starting <strong>{{ optional($pickupWindowStartsAt)->format('d F Y, g:i A') }}</strong> and must be completed by <strong>{{ optional($pickupWindowEndsAt)->format('d F Y, g:i A') }}</strong>. Refresh this page when the pickup window starts.</p>
                        </div>
                    @elseif($pickupWindowPassed)
                        <div class="callout warning release-window-notice" id="physical-release-availability">
                            <strong>Do not record a late physical release.</strong>
                            <p>The claim window ended on <strong>{{ optional($pickupWindowEndsAt)->format('d F Y, g:i A') }}</strong>. Set a new pickup schedule before handing over the items.</p>
                        </div>
                    @endif
                @else
                    <p class="release-step-note" id="physical-release-availability">Complete pickup scheduling and item preparation before recording physical handover.</p>
                @endif
                @include('custody.partials.physical-release-form')
            </li>
        </ol>
        <div class="release-process-note"><x-icon name="information" size="19" /><span>Physical Release records the actual handover and moves custody to Released / On Custody. It does not create or approve a new Gate Pass.</span></div>
    </article>
</section>

@include('custody.partials.release-process-interactions')

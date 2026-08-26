@extends('layouts.app', ['title' => 'For Approval'])

@section('content')

<section class="page-heading approval-queue-heading">
    <div>
        <p class="eyebrow">SPMU approval review</p>

        <h1>Requests for Approval</h1>

        <p>
            Review the submitted request, signed supporting documents,
            requested quantities, borrowing dates, and current availability.
            Approval allocates and holds the approved quantities for pickup;
            physical issuance happens later through the SPMU Action Officer.
        </p>
    </div>

    <a
        class="button secondary ui-pressable"
        href="{{ route('requests.index') }}"
    >
        View Request Records
    </a>
</section>

<section class="content-area">
    <div class="approval-browser-toolbar">
        <label>Search
            <input type="search" id="approval-search" placeholder="Search request no., borrower, event, or item..." autocomplete="off">
        </label>
        <label>Sort
            <select id="approval-sort"><option value="newest">Newest</option><option value="oldest">Oldest</option></select>
        </label>
    </div>

    <div
        class="approval-queue-list"
        aria-label="Requests awaiting SPMU approval"
    >
        @forelse($requests as $request)

            @php
                $version = $request->currentVersion;

                $submittedAt =
                    $version->submitted_at
                    ?: $request->updated_at;

                $itemCount = $version->items->count();

                $currentSupporting = $version->supportingDocuments
                    ->where('is_current', true);

                $requestLetter = $currentSupporting->firstWhere(
                    'document_type',
                    App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                );

                $requiresPtc =
                    (bool) $version->represents_student_activity;

                $ptc = $currentSupporting->firstWhere(
                    'document_type',
                    App\Models\RequestSupportingDocument::TYPE_PERMISSION_TO_CONDUCT
                );
            @endphp

            <a
                class="approval-queue-item ui-pressable"
                href="{{ route('requests.show', $request) }}"
                data-approval-record
                data-created="{{ optional($submittedAt)->timestamp ?? 0 }}"
                data-search="{{ strtolower(trim($request->request_no.' '.$request->borrower->full_name.' '.($version->purpose_event ?? '').' '.$version->items->map(fn($ri) => $ri->inventoryItem?->unique_description)->filter()->implode(' '))) }}"
            >
                <span class="approval-queue-primary">
                    <strong>
                        {{ $version->purpose_event ?: 'Borrowing request' }}
                    </strong>

                    <span class="approval-queue-borrower">
                        {{ $request->borrower->full_name }}
                    </span>

                    <span class="record-reference">
                        {{ $request->request_no }}
                    </span>
                </span>

                <span class="approval-queue-schedule">
                    <span class="approval-queue-period">
                        <span>
                            <small>Items needed from</small>

                            <strong>
                                <x-date
                                    :value="$version->schedule_date ?: $version->needed_from"
                                />
                            </strong>
                        </span>

                        <span aria-hidden="true">&rarr;</span>

                        <span>
                            <small>Expected return</small>

                            <strong>
                                <x-date
                                    :value="$version->return_date ?: $version->return_due_at"
                                />
                            </strong>
                        </span>
                    </span>

                    <small>
                        Submitted
                        <x-date
                            :value="$submittedAt"
                            with-time
                        />
                    </small>
                </span>

                <span class="approval-queue-indicators">

                    <span>
                        {{ $itemCount }}
                        {{ \Illuminate\Support\Str::plural('item type', $itemCount) }}
                    </span>

                    @if($version->off_campus)
                        <span class="context-chip context-chip-warning">
                            Off-campus
                        </span>
                    @else
                        <span class="context-chip">
                            On-campus
                        </span>
                    @endif

                    <span
                        class="context-chip {{ $requestLetter ? '' : 'context-chip-warning' }}"
                    >
                        Request Letter:
                        {{ $requestLetter ? 'Attached' : 'Missing' }}
                    </span>

                    @if($requiresPtc)
                        <span
                            class="context-chip {{ $ptc ? '' : 'context-chip-warning' }}"
                        >
                            PTC:
                            {{ $ptc ? 'Attached' : 'Missing' }}
                        </span>
                    @endif

                    <x-status-badge
                        :status="$request->status->value"
                        label="For Approval"
                    />
                </span>

                <span class="approval-queue-action">
                    {{ $canDecide ? 'Review & Decide' : 'Review request & documents' }}

                    <x-icon
                        name="chevron-right"
                        size="17"
                    />
                </span>
            </a>

        @empty

            <div class="empty-state approval-queue-empty">
                <strong>
                    No borrowing requests are waiting for approval.
                </strong>

                <span>
                    Newly submitted requests will appear here automatically
                    when they are ready for SPMU review.
                </span>
            </div>

        @endforelse
    </div>

    <div class="empty-state top-gap" id="approval-filter-empty" hidden><strong>No matching approval requests.</strong><span>Try another search term.</span></div>
</section>
<style>
.approval-browser-toolbar{display:grid;grid-template-columns:minmax(280px,1fr) minmax(150px,190px);gap:12px;align-items:end;margin-bottom:14px;padding:14px;background:var(--surface-elevated);border:1px solid var(--border);border-radius:var(--radius)}
.approval-browser-toolbar label{display:grid;gap:6px;font-size:12px;font-weight:800;color:var(--muted)}
.approval-browser-toolbar input,.approval-browser-toolbar select{min-height:42px;width:100%}
@media(max-width:680px){.approval-browser-toolbar{grid-template-columns:1fr}}
</style>
<script>
(() => { const list=document.querySelector('.approval-queue-list'); const rows=[...document.querySelectorAll('[data-approval-record]')]; const search=document.getElementById('approval-search'); const sort=document.getElementById('approval-sort'); const empty=document.getElementById('approval-filter-empty'); if(!list||!rows.length||!search||!sort)return; const render=()=>{const q=search.value.trim().toLowerCase();const ordered=[...rows].sort((a,b)=>(Number(b.dataset.created)-Number(a.dataset.created))*(sort.value==='newest'?1:-1));ordered.forEach(r=>list.appendChild(r));let n=0;rows.forEach(r=>{const show=!q||r.dataset.search.includes(q);r.hidden=!show;if(show)n++});if(empty)empty.hidden=n>0};search.addEventListener('input',render);sort.addEventListener('change',render);render(); })();
</script>
@endsection
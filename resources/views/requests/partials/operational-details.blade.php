<section class="content-grid two request-operational-grid">
    <article class="card request-information-card" aria-labelledby="request-information-title">
        <div class="card-header request-section-title">
            <x-icon name="information" size="20" />
            <h2 id="request-information-title">Borrowing Information</h2>
        </div>
        <dl class="detail-list request-information-list">
            <dt>Borrower</dt>
            <dd>{{ $borrowingRequest->borrower->full_name }}</dd>
            <dt>Office / College / Unit</dt>
            <dd>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</dd>
            <dt>Event</dt>
            <dd>{{ $v->purpose_event }}</dd>
            <dt>Location</dt>
            <dd>{{ $v->location }}</dd>
            <dt>Items Needed From</dt>
            <dd>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</dd>
            <dt>Expected Return Date</dt>
            <dd>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</dd>
        </dl>
    </article>

    @php
        $laundryAccomplishedFile = $custody?->laundryJob?->latestEvidence?->file;
        $laundryAccomplishedVerified = (bool) $custody?->laundryJob?->form_verified_at;
        $gatePassAccomplishedFile = $custody?->gatePass?->accomplishedFile;
        $gatePassAccomplishedVerified = (bool) $custody?->gatePass?->verified_at;
        $hasOperationalArchive = (bool) $borrowerSlipDocument
            || (bool) $laundryFormDocument
            || (bool) $gatePassDocument
            || (bool) $laundryAccomplishedFile
            || (bool) $gatePassAccomplishedFile;
    @endphp

    <article class="card request-documents-card" aria-labelledby="request-documents-title">
        <div class="card-header request-section-title request-documents-heading">
            <div class="request-documents-heading-copy">
                <x-icon name="requests" size="20" />
                <div>
                    <h2 id="request-documents-title">Documents</h2>
                    @if($requestIsCompleted)
                        <small>Historical copies retained with the completed transaction.</small>
                    @endif
                </div>
            </div>
            @if($requestIsCompleted)
                <span class="status-badge status-success">Archived</span>
            @endif
        </div>

        <div class="request-operational-document-list">
            <p class="request-document-group-label">Request Documents</p>

            @forelse($currentDocs->sortBy(fn ($doc) => $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER ? 1 : 0) as $doc)
                <div class="request-operational-document">
                    <div class="request-operational-document-copy">
                        <strong>{{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                            ? 'Approved Borrowing Request Letter'
                            : 'Permission to Conduct Letter' }}</strong>
                        <small>Version {{ $doc->version_no }} · {{ str($doc->verification_status)->replace('_', ' ')->title() }}</small>
                    </div>
                    <a class="button secondary small ui-pressable request-document-link"
                        href="{{ route('files.preview', $doc->file, false) }}"
                        aria-label="Preview {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER ? 'Approved Borrowing Request Letter' : 'Permission to Conduct Letter' }}">
                        Preview
                    </a>
                </div>
            @empty
                <div class="request-document-empty">No current scanned request document.</div>
            @endforelse

            @if($custody)
                <p class="request-document-group-label">Release Documents</p>

                <div class="request-operational-document">
                    <div class="request-operational-document-copy">
                        <strong>Borrower Slip</strong>
                        <small>{{ $borrowerSlipDocument ? 'Generated · Retained with transaction' : 'Not generated' }}</small>
                    </div>
                    @if($borrowerSlipDocument)
                        <a class="button secondary small ui-pressable request-document-link"
                            href="{{ route('documents.preview', $borrowerSlipDocument) }}">Preview</a>
                    @else
                        <span class="status-badge status-neutral">Not available</span>
                    @endif
                </div>

                @if($requestHasLaundry)
                    <div class="request-operational-document">
                        <div class="request-operational-document-copy">
                            <strong>Laundry Form</strong>
                            <small>{{ $laundryFormDocument ? 'Generated · Original operational form' : 'Not generated' }}</small>
                        </div>
                        @if($laundryFormDocument)
                            <a class="button secondary small ui-pressable request-document-link"
                                href="{{ route('documents.preview', $laundryFormDocument) }}">Preview</a>
                        @else
                            <span class="status-badge status-neutral">Not available</span>
                        @endif
                    </div>

                    <div class="request-operational-document">
                        <div class="request-operational-document-copy">
                            <strong>Accomplished Laundry Form</strong>
                            <small>
                                @if($laundryAccomplishedFile)
                                    {{ $laundryAccomplishedVerified ? 'Verified · Historical return evidence' : 'Uploaded · Awaiting verification' }}
                                @else
                                    No accomplished scan on file
                                @endif
                            </small>
                        </div>
                        @if($laundryAccomplishedFile)
                            <a class="button secondary small ui-pressable request-document-link"
                                href="{{ route('files.preview', $laundryAccomplishedFile, false) }}">Preview</a>
                        @else
                            <span class="status-badge status-neutral">Not available</span>
                        @endif
                    </div>
                @endif

                @if($requestHasOffCampus)
                    <div class="request-operational-document">
                        <div class="request-operational-document-copy">
                            <strong>Gate Pass</strong>
                            <small>{{ $gatePassDocument ? 'Generated · Original operational form' : 'Not generated' }}</small>
                        </div>
                        @if($gatePassDocument)
                            <a class="button secondary small ui-pressable request-document-link"
                                href="{{ route('documents.preview', $gatePassDocument) }}">Preview</a>
                        @else
                            <span class="status-badge status-neutral">Not available</span>
                        @endif
                    </div>

                    <div class="request-operational-document">
                        <div class="request-operational-document-copy">
                            <strong>Accomplished Gate Pass</strong>
                            <small>
                                @if($gatePassAccomplishedFile)
                                    {{ $gatePassAccomplishedVerified ? 'Verified · Historical off-campus release record' : 'Uploaded · Awaiting verification' }}
                                @else
                                    No accomplished scan on file
                                @endif
                            </small>
                        </div>
                        @if($gatePassAccomplishedFile)
                            <a class="button secondary small ui-pressable request-document-link"
                                href="{{ route('files.preview', $gatePassAccomplishedFile, false) }}">Preview</a>
                        @else
                            <span class="status-badge status-neutral">Not available</span>
                        @endif
                    </div>
                @endif
            @endif

            @if($custody && !$hasOperationalArchive)
                <div class="request-document-empty">Operational documents will appear here once generated or uploaded.</div>
            @endif
        </div>
    </article>
</section>

<section class="content-area">
    <article class="card request-items-card" aria-labelledby="request-items-title">
        <div class="card-header request-section-title">
            <x-icon name="inventory" size="20" />
            <h2 id="request-items-title">Requested Items</h2>
        </div>
        <div class="table-wrap">
            <table class="request-operational-items">
                <thead>
                    <tr><th scope="col">Item</th><th scope="col">Requested</th><th scope="col">Approved</th><th scope="col">Use</th></tr>
                </thead>
                <tbody>
                    @forelse($v->items as $item)
                        <tr>
                            <td>{{ $item->description_snapshot }}</td>
                            <td>{{ $item->requested_quantity + 0 }} {{ $item->unit_snapshot }}</td>
                            <td>{{ $item->approved_quantity === null ? 'Not approved yet' : ($item->approved_quantity + 0).' '.$item->unit_snapshot }}</td>
                            <td>{{ str($item->use_location)->replace('_', ' ')->title() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No requested items.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </article>
</section>

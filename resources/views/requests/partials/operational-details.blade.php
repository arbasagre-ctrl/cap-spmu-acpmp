<section class="content-grid two request-operational-grid">
    <article class="card request-information-card" aria-labelledby="request-information-title">
        <div class="card-header request-section-title">
            <x-icon name="information" size="20" />
            <h2 id="request-information-title">Borrowing Information</h2>
        </div>
        <dl class="detail-list request-information-list">
            <dt>Borrower</dt>
            <dd>{{ $borrowingRequest->borrower->full_name }}</dd>
            <dt>Office / Department</dt>
            <dd>{{ $borrowingRequest->borrower->organizationalUnit?->unit_name ?: '—' }}</dd>
            <dt>Event</dt>
            <dd>{{ $v->purpose_event }}</dd>
            <dt>Location</dt>
            <dd>{{ $v->location }}</dd>
            <dt>Schedule Date</dt>
            <dd>{{ optional($v->schedule_date ?: $v->needed_from)->format('d F Y') }}</dd>
            <dt>Expected Return Date</dt>
            <dd>{{ optional($v->return_date ?: $v->return_due_at)->format('d F Y') }}</dd>
        </dl>
    </article>

    <article class="card request-documents-card" aria-labelledby="request-documents-title">
        <div class="card-header request-section-title">
            <x-icon name="requests" size="20" />
            <h2 id="request-documents-title">Documents</h2>
        </div>
        <div class="request-operational-document-list">
            @forelse($currentDocs->sortBy(fn ($doc) => $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER ? 1 : 0) as $doc)
                <div class="request-operational-document">
                    <div class="request-operational-document-copy">
                        <strong>{{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER
                            ? 'Approved Borrowing Request Letter'
                            : 'Permission to Conduct Letter' }}</strong>
                        <small>Version {{ $doc->version_no }} · {{ str($doc->verification_status)->replace('_', ' ')->title() }}</small>
                    </div>
                    <a class="button secondary small ui-pressable request-document-link"
                        href="{{ route('files.show', $doc->file, false) }}"
                        target="_blank" rel="noopener"
                        aria-label="View {{ $doc->document_type === App\Models\RequestSupportingDocument::TYPE_REQUEST_LETTER ? 'Approved Borrowing Request Letter' : 'Permission to Conduct Letter' }}">
                        View
                    </a>
                </div>
            @empty
                <div class="empty-state"><strong>No current scanned supporting document.</strong></div>
            @endforelse
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

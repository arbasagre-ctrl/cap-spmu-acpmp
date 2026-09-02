                @forelse($documents->whereNotIn('status', ['SUPERSEDED', 'INVALIDATED', 'EXPIRED']) as $document)
                    @php
                        $operationalDocumentTypes = [
                            'BORROWER_SLIP',
                            'GATE_PASS',
                            'LAUNDRY_FORM',
                        ];

                        $isReleaseOperationalDocument = in_array(
                            $document->document_type,
                            $operationalDocumentTypes,
                            true
                        );

                        $documentDisplayStatus = match (true) {
                            $document->document_type === 'GATE_PASS'
                                && in_array($custody->gatePass?->status, ['READY_FOR_PRINTING', 'VERIFIED'], true)
                                    => 'APPROVED GATE PASS — VALIDATE PRESENTED COPY',
                            $document->document_type === 'GATE_PASS'
                                    => 'INVALID — DO NOT RELEASE',
                            $isReleaseOperationalDocument
                                    => 'Borrower Copy / Reference',
                            default => str($document->status)->replace('_', ' ')->upper(),
                        };
                    @endphp

                    <div class="release-document-row">
                        <div class="release-document-copy">
                            <x-icon name="requests" size="17" />
                            <strong>{{ str($document->document_type)->replace('_', ' ')->title() }}</strong>
                            <small>{{ $documentDisplayStatus }}</small>
                        </div>
                        <a class="button secondary small ui-pressable release-outline" href="{{ route('documents.view', $document) }}" target="_blank" rel="noopener">
                            View
                        </a>
                    </div>
                @empty
                    <div class="empty-state">
                        <strong>No operational form generated yet.</strong>
                    </div>
                @endforelse

<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include('documents.accountability.partials.official-styles')
</head>
<body>
<section class="official-document">
    <table class="document-header" role="presentation">
        <tr>
            <td class="document-logo-cell"><img class="document-logo" src="{{ $logoDataUri }}" alt="CSPC logo"></td>
            <td class="document-header-copy">
                <strong>CAMARINES SUR POLYTECHNIC COLLEGES</strong>
                <span>Supply and Property Management Unit · Nabua, Camarines Sur</span>
            </td>
        </tr>
    </table>

    <div class="document-title">
        <h1>Restriction Notice</h1>
        <div class="document-meta">
            <strong>Reference</strong> {{ $caseReference ?: '—' }}
            <span class="meta-separator">•</span>
            <strong>Date Issued</strong> {{ $issuedDate }}
        </div>
    </div>

    <div class="section-title">Borrower Information</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Borrower</span><span class="field-value">{{ $restriction->borrower?->full_name ?: '—' }}</span></td>
            <td><span class="field-label">Office / College / Unit</span><span class="field-value">{{ $officeUnit ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Custody No.</span><span class="field-value">{{ $custodyNo ?: '—' }}</span></td>
            <td><span class="field-label">Linked Case</span><span class="field-value">{{ $caseReference ?: '—' }}</span></td>
        </tr>
    </table>

    <div class="section-title">Restriction Details</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Restriction Type</span><span class="field-value">{{ $restrictionTypeLabel }}</span></td>
            <td><span class="field-label">Status</span><span class="field-value">{{ $restriction->status === 'ACTIVE' ? 'Active' : 'Lifted' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Effective From</span><span class="field-value">{{ $effectiveFrom }}</span></td>
            <td><span class="field-label">Effective Until</span><span class="field-value">{{ $effectiveTo ?: 'Until the accountability is resolved' }}</span></td>
        </tr>
    </table>

    <div class="section-title">Reason</div>
    <p class="decision-copy">{{ $restriction->reason ?: 'Unresolved property accountability.' }}</p>

    <p class="decision-copy">Borrowing privileges are temporarily restricted until this accountability is resolved. This notice is a factual record of the restriction and is not itself a decision or a formal sanction.</p>

    <div class="document-footer">
        <table role="presentation"><tr>
            <td>SPMU accountability document. Imposed by {{ $imposedByName }}{{ $imposedByDesignation ? ' — '.$imposedByDesignation : '' }}.</td>
            <td>{{ $caseReference ?: '' }}</td>
        </tr></table>
    </div>
</section>
</body>
</html>

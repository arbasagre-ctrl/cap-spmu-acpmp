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
        <h1>Late Return Notice</h1>
        <div class="document-meta">
            <strong>Reference</strong> {{ $reference }}
            <span class="meta-separator">•</span>
            <strong>Date Issued</strong> {{ $headDate }}
        </div>
    </div>

    <div class="section-title">Borrower and Transaction Information</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Borrower</span><span class="field-value">{{ $borrowerName ?: '—' }}</span></td>
            <td><span class="field-label">Office / Unit</span><span class="field-value">{{ $officeUnit ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Request No.</span><span class="field-value">{{ $requestNo ?: '—' }}</span></td>
            <td><span class="field-label">Custody No.</span><span class="field-value">{{ $custodyNo ?: '—' }}</span></td>
        </tr>
    </table>

    <div class="section-title">Confirmed Late-Return Assessment</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Expected Return Date</span><span class="field-value">{{ $expectedReturnDate }}</span></td>
            <td><span class="field-label">Actual Return Date</span><span class="field-value">{{ $actualReturnDate }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Final Late Days</span><span class="field-value emphasis">{{ $lateDays }}</span></td>
            <td><span class="field-label">Final Disposition</span><span class="field-value emphasis">{{ $disposition }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Approved Daily Rate</span><span class="field-value">{{ $rate !== null ? 'PHP '.number_format($rate, 2) : 'Not applicable' }}</span></td>
            <td><span class="field-label">Assessed Amount</span><span class="field-value">PHP {{ number_format($amount, 2) }}</span></td>
        </tr>
    </table>

    <div class="section-title">Affected Property</div>
    <table class="document-table">
        <thead>
        <tr>
            <th>Description</th>
            <th style="width:18%" class="numeric">Quantity</th>
            <th style="width:20%">Unit</th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $item)
            <tr>
                <td>{{ $item['description'] }}</td>
                <td class="numeric">{{ (float) $item['quantity'] == (int) $item['quantity'] ? (int) $item['quantity'] : number_format((float) $item['quantity'], 2) }}</td>
                <td>{{ $item['unit'] ?: '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3">No item detail is available for this historical transaction.</td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Assessment Basis / Remarks</div>
    <p class="decision-copy">{{ $decisionBasis ?: 'Confirmed late-return assessment based on the recorded expected and actual physical return dates.' }}</p>

    <div class="notice-box {{ $amount > 0 ? 'warning-box' : '' }}">
        <strong>Document Purpose</strong>
        <p>This Late Return Notice is the formal record of the confirmed late-return assessment.</p>
        @if($amount > 0)
            <p>A separate Billing Statement is the financial document used for CSPC Cashier settlement. This Late Return Notice is not an Official Receipt.</p>
        @else
            <p>No amount is payable under the recorded disposition unless a separate authorized accountability action is issued.</p>
        @endif
        <p>Any administrative offense or sanction is handled separately and is not created automatically by this notice.</p>
    </div>

    <div class="section-title">Workflow Confirmation</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">AO Confirmation</span><span class="field-value">{{ $aoConfirmedBy ?: 'SPMU Action Officer' }}{{ $aoConfirmedAt ? ' · '.$aoConfirmedAt : '' }}</span></td>
            <td><span class="field-label">Generated</span><span class="field-value">{{ $generatedAt }}</span></td>
        </tr>
    </table>

    <table class="signature-table" role="presentation">
        <tr>
            <td colspan="2" style="width: 100%; padding-left: 25%; padding-right: 25%;">
                <div class="signature-label">Approved By — SPMU Head/Admin</div>
                <div class="signature-space">{!! $headSignatureHtml ?: '' !!}</div>
                <div class="signature-name">{{ $headName }}</div>
                <div class="signature-role">{{ $headDesignation }}</div>
                <div class="signature-date">{{ $headDate }}</div>
            </td>
        </tr>
    </table>

    <div class="document-footer">
        <table role="presentation"><tr>
            <td>SPMU late-return accountability record.</td>
            <td>{{ $reference }}{{ $custodyNo ? ' · '.$custodyNo : '' }}</td>
        </tr></table>
    </div>
</section>
</body>
</html>

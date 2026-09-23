<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    @include('documents.accountability.partials.official-styles')
</head>
@php($isPreReturn = $isPreReturn ?? false)
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
            <strong>Date Issued</strong> {{ $headDate ?: $generatedAt }}
            @if($isPreReturn)
                <span class="meta-separator">•</span>
                <strong>Status</strong> Preliminary
            @endif
        </div>
    </div>

    @if($isPreReturn)
        <div class="notice-box">
            <strong>Preliminary Notice</strong>
            <p>This item was not returned by the Expected Return Date shown below. This notice states the official late-return fee rate per day only. The final number of late days and the total amount due, if any, are not yet determined and are not shown here - they will be established once the item is physically returned, and billed separately through a Late Return Billing Statement.</p>
        </div>
    @endif

    <div class="section-title">Borrower and Transaction Information</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Borrower</span><span class="field-value">{{ $borrowerName ?: '—' }}</span></td>
            <td><span class="field-label">Office / College / Unit</span><span class="field-value">{{ $officeUnit ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Request No.</span><span class="field-value">{{ $requestNo ?: '—' }}</span></td>
            <td><span class="field-label">Custody No.</span><span class="field-value">{{ $custodyNo ?: '—' }}</span></td>
        </tr>
    </table>

    <div class="section-title">{{ $isPreReturn ? 'Preliminary Late-Return Notice' : 'Finalized Late-Return Assessment' }}</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Expected Return Date</span><span class="field-value">{{ $expectedReturnDate }}</span></td>
            <td><span class="field-label">Actual Return Date</span><span class="field-value">{{ $actualReturnDate }}</span></td>
        </tr>
        @unless($isPreReturn)
        <tr>
            <td><span class="field-label">Final Late Days</span><span class="field-value emphasis">{{ $lateDays }}</span></td>
            <td><span class="field-label">Final Disposition</span><span class="field-value emphasis">{{ $disposition }}</span></td>
        </tr>
        @endunless
        <tr>
            <td><span class="field-label">Official Late-Return Fee Rate</span><span class="field-value">{{ $rate !== null ? 'PHP '.number_format($rate, 2).' per day' : 'Not applicable' }}</span></td>
            <td><span class="field-label">Billing Information</span><span class="field-value">{{ $isPreReturn ? 'Determined once the item is physically returned.' : ($amount > 0 ? 'See the separate Late Return Billing Statement for the total amount due.' : 'No separate billing required.') }}</span></td>
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
    <p class="decision-copy">{{ $decisionBasis ?: 'Finalized late-return assessment based on the recorded expected and actual physical return dates.' }}</p>

    @if($isPreReturn)
        <div class="notice-box warning-box">
            <strong>Document Purpose</strong>
            <p>This Late Return Notice formally informs the borrower that the item is overdue and states only the official late-return fee rate per day.</p>
            <p>The final number of late days and the total amount due are intentionally not stated in this notice. They are not yet determined and will be established once the item is physically returned, then billed separately through a Late Return Billing Statement. This Late Return Notice is not an Official Receipt.</p>
            <p>Any administrative offense or sanction is handled separately and is not created automatically by this notice.</p>
        </div>
    @else
    <div class="notice-box {{ $amount > 0 ? 'warning-box' : '' }}">
        <strong>Document Purpose</strong>
        <p>This Late Return Notice formally informs the borrower of the confirmed late return, the final number of late days, and the official late-return fee rate per day.</p>
        @if($amount > 0)
            <p>The total amount due is intentionally not stated in this notice. A separate Late Return Billing Statement is the financial document used for CSPC Cashier settlement. This Late Return Notice is not an Official Receipt.</p>
        @else
            <p>No amount is payable under the recorded disposition unless a separate authorized accountability action is issued.</p>
        @endif
        <p>Any administrative offense or sanction is handled separately and is not created automatically by this notice.</p>
    </div>
    @endif

    <div class="section-title">{{ $isPreReturn ? 'Notice Issuance' : 'Assessment Finalization' }}</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">{{ $isPreReturn ? 'Issued By' : 'Finalized By' }}</span><span class="field-value">{{ $aoConfirmedAt ? ($aoConfirmedBy.' · '.$aoConfirmedAt) : ($isPreReturn ? 'Automatically, upon the custody becoming overdue' : 'Automatically, from the recorded physical return') }}</span></td>
            <td><span class="field-label">Generated</span><span class="field-value">{{ $generatedAt }}</span></td>
        </tr>
    </table>

    @unless($isPreReturn)
    <table class="signature-table" role="presentation">
        <tr>
            <td colspan="2" style="width: 100%; padding-left: 25%; padding-right: 25%;">
                <div class="signature-label">Confirmed By — SPMU Head</div>
                <div class="signature-space">{!! $headSignatureHtml ?: '' !!}</div>
                <div class="signature-name">{{ $headName }}</div>
                <div class="signature-role">{{ $headDesignation }}</div>
                <div class="signature-date">{{ $headDate }}</div>
            </td>
        </tr>
    </table>
    @endunless

    <div class="document-footer">
        <table role="presentation"><tr>
            <td>SPMU late-return accountability record.</td>
            <td>{{ $reference }}{{ $custodyNo ? ' · '.$custodyNo : '' }}</td>
        </tr></table>
    </div>
</section>
</body>
</html>

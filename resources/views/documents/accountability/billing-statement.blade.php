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
        <h1>Billing Statement / Assessment Notice</h1>
        <div class="document-meta">
            <strong>Billing No.</strong> {{ $billing->billing_no }}
            <span class="meta-separator">•</span>
            <strong>Date Issued</strong> {{ $issuedDate }}
            @if($dueDate)
                <span class="meta-separator">•</span>
                <strong>Due Date</strong> {{ $dueDate }}
            @endif
        </div>
    </div>

    <div class="section-title">Borrower and Transaction Information</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Borrower</span><span class="field-value">{{ $billing->borrower?->full_name ?: '—' }}</span></td>
            <td><span class="field-label">Office / Unit</span><span class="field-value">{{ $officeUnit ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Request No.</span><span class="field-value">{{ $requestNo ?: '—' }}</span></td>
            <td><span class="field-label">Custody No.</span><span class="field-value">{{ $custodyNo ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Accountability / Incident Ref.</span><span class="field-value">{{ $incidentNo ?: '—' }}</span></td>
            <td><span class="field-label">Billing Status</span><span class="field-value">{{ str($billing->status)->replace('_', ' ')->title() }}</span></td>
        </tr>
    </table>

    <div class="section-title">Assessment Details</div>
    <table class="document-table">
        <thead>
        <tr>
            <th style="width:20%">Type</th>
            <th style="width:32%">Description</th>
            <th style="width:31%">Assessment Basis</th>
            <th style="width:17%" class="numeric">Amount</th>
        </tr>
        </thead>
        <tbody>
        @foreach($billing->lines as $line)
            <tr>
                <td>{{ str($line->line_type)->replace('_', ' ')->title() }}</td>
                <td>{{ $line->description }}</td>
                <td>{{ $line->basis ?: 'Approved accountability assessment' }}</td>
                <td class="numeric">PHP {{ number_format((float) $line->amount, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="total-box">
        <table role="presentation">
            <tr><td class="label">Total Amount Due</td><td class="amount">PHP {{ number_format((float) $billing->total_amount, 2) }}</td></tr>
        </table>
    </div>

    <div class="section-title">Payment Instruction</div>
    <p class="decision-copy">
        Present this Billing Statement to the CSPC Cashier. After payment, present the Cashier-issued Official Receipt to SPMU for verification.
        <strong>This Billing Statement is not an Official Receipt.</strong>
    </p>

    @if($billing->remarks)
        <div class="section-title">Remarks</div>
        <p class="decision-copy">{{ $billing->remarks }}</p>
    @endif

    <table class="signature-table" role="presentation">
        <tr>
            <td colspan="2" style="width: 100%; padding-left: 25%; padding-right: 25%;">
                <div class="signature-label">Head Authorization — SPMU</div>
                <div class="signature-space">{!! $headSignatureHtml ?: "" !!}</div>
                <div class="signature-name">{{ $headName }}</div>
                <div class="signature-role">{{ $headDesignation }}</div>
                <div class="signature-date">{{ $headDecisionDate ?: '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="document-footer">
        <table role="presentation"><tr>
            <td>SPMU billing and assessment record.</td>
            <td>{{ $billing->billing_no }}</td>
        </tr></table>
    </div>
</section>
</body>
</html>

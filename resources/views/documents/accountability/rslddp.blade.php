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
        <h1>Report of Semi-Expendable/Lost, Damaged, Destroyed Property</h1>
        <div class="document-meta">
            <strong>RSLDDP Ref.</strong> {{ $incident->rslddp_reference ?: '—' }}
            <span class="meta-separator">•</span>
            <strong>Incident No.</strong> {{ $incident->incident_no }}
            <span class="meta-separator">•</span>
            <strong>Generated</strong> {{ $generatedAt }}
        </div>
    </div>

    @if($isProvisional)
        <div class="notice-box warning-box">
            <strong>PROVISIONAL FORMAT</strong>
            <p>Official content, appraisal fields, and signatories pending institutional approval. This layout is replaceable and is not the final approved institutional RSLDDP.</p>
        </div>
    @endif

    <div class="section-title">Borrower and Transaction Information</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Borrower</span><span class="field-value">{{ $incident->borrower?->full_name ?: '—' }}</span></td>
            <td><span class="field-label">Office / College / Unit</span><span class="field-value">{{ $officeUnit ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Request No.</span><span class="field-value">{{ $requestNo ?: '—' }}</span></td>
            <td><span class="field-label">Custody No.</span><span class="field-value">{{ $incident->custody?->custody_no ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Incident Type</span><span class="field-value">{{ str($incident->incident_type)->replace('_', ' ')->title() }}</span></td>
            <td><span class="field-label">Reported</span><span class="field-value">{{ $incident->reported_at?->format('F j, Y g:i A') ?: '—' }}</span></td>
        </tr>
    </table>

    <div class="section-title">Affected Property</div>
    <table class="document-table">
        <thead>
        <tr>
            <th style="width:46%">Item</th>
            <th style="width:14%" class="numeric">Qty</th>
            <th style="width:20%">Observed Condition</th>
            <th style="width:20%" class="numeric">Assessed Value</th>
        </tr>
        </thead>
        <tbody>
        @forelse($items as $item)
            <tr>
                <td>{{ $item['description'] }}</td>
                <td class="numeric">{{ $item['quantity'] }}</td>
                <td>{{ $item['condition'] }}</td>
                <td class="numeric">{{ $item['assessed_value'] !== null ? 'PHP '.number_format($item['assessed_value'], 2) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="center">No affected property lines recorded.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="section-title">Finding Narrative</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Police Blotter Reference</span><span class="field-value">{{ $incident->police_blotter_reference ?: 'Not applicable' }}</span></td>
            <td><span class="field-label">Remarks</span><span class="field-value">{{ $incident->remarks ?: 'None' }}</span></td>
        </tr>
    </table>

    <table class="signature-table" role="presentation">
        <tr>
            <td>
                <div class="signature-label">Reported/Inspected By — SPMU Action Officer</div>
                <div class="signature-space"></div>
                <div class="signature-name">{{ $reportedByName }}</div>
                <div class="signature-role">{{ $reportedByDesignation ?: 'SPMU Action Officer' }}</div>
                <div class="signature-date">{{ $reportedByDate ?: '—' }}</div>
            </td>
            <td>
                <div class="signature-label">Reviewed/Confirmed By — SPMU Head/Admin</div>
                <div class="signature-space">{!! $headSignatureHtml ?: '' !!}</div>
                <div class="signature-name">{{ $headName }}</div>
                <div class="signature-role">{{ $headDesignation ?: 'SPMU Head/Admin' }}</div>
                <div class="signature-date">{{ $headDate ?: '—' }}</div>
            </td>
        </tr>
    </table>

    <div class="document-footer">
        <table role="presentation"><tr>
            <td>RSLDDP{{ $isProvisional ? ' (Provisional Format)' : '' }} — {{ $incident->incident_no }}</td>
            <td>{{ $incident->rslddp_reference ?: '' }}</td>
        </tr></table>
    </div>
</section>
</body>
</html>

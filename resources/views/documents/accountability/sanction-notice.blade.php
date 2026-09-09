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
        <h1>Administrative Sanction Notice</h1>
        <div class="document-meta">
            <strong>Offense Level</strong> {{ $offenseLabel }}
            <span class="meta-separator">•</span>
            <strong>Date Confirmed</strong> {{ $confirmedDate }}
        </div>
    </div>

    <div class="section-title">Borrower and Transaction Information</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Borrower</span><span class="field-value">{{ $sanction->borrower?->full_name ?: '—' }}</span></td>
            <td><span class="field-label">Office / Unit</span><span class="field-value">{{ $officeUnit ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Request No.</span><span class="field-value">{{ $requestNo ?: '—' }}</span></td>
            <td><span class="field-label">Custody No.</span><span class="field-value">{{ $custodyNo ?: '—' }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Academic Period</span><span class="field-value">{{ $academicPeriod }}</span></td>
            <td><span class="field-label">Administrative Status</span><span class="field-value">Confirmed</span></td>
        </tr>
    </table>

    <div class="section-title">Confirmed Offense Basis</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Applicable Finding(s)</span><span class="field-value">{{ $reasonText }}</span></td>
            <td>
                @if($sanction->remarks)
                    <span class="field-label">Decision Basis</span>
                    <span class="field-value">{{ $sanction->remarks }}</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="section-title">Administrative Sanction</div>
    <table class="info-grid" role="presentation">
        <tr>
            <td><span class="field-label">Offense Level</span><span class="field-value">{{ $offenseLabel }}</span></td>
            <td><span class="field-label">Sanction</span><span class="field-value emphasis">{{ $sanction->sanction_label }}</span></td>
        </tr>
        <tr>
            <td><span class="field-label">Effective From</span><span class="field-value">{{ $effectiveFrom }}</span></td>
            <td><span class="field-label">Effective Until</span><span class="field-value">{{ $effectiveTo ?: 'Not applicable' }}</span></td>
        </tr>
    </table>

    <div class="notice-box {{ $hasBorrowingSuspension ? 'warning-box' : '' }}">
        <strong>Sanction Effect</strong>
        @if($hasBorrowingSuspension)
            <p>{{ $sanction->sanction_label }} is effective for the period stated above.</p>
        @else
            <p>{{ $sanction->sanction_label }} only — no borrowing suspension is imposed by this administrative sanction.</p>
        @endif
        <p>Any separate property, billing, overdue, or other unresolved borrowing restriction remains effective until that obligation is independently resolved.</p>
    </div>

    <table class="signature-table" role="presentation">
        <tr>
            <td></td>
            <td>
                <div class="signature-label">Confirmed By — SPMU Head</div>
                <div class="signature-space">{!! $headSignatureHtml ?: "" !!}</div>
                <div class="signature-name">{{ $headName }}</div>
                <div class="signature-role">{{ $headDesignation }}</div>
                <div class="signature-date">{{ $confirmedDate }}</div>
            </td>
        </tr>
    </table>

    <div class="document-footer">
        <table role="presentation"><tr>
            <td>SPMU administrative accountability record.</td>
            <td>{{ $offenseLabel }}</td>
        </tr></table>
    </div>
</section>
</body>
</html>

<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Borrowing Request Letter - {{ $borrowingRequest->request_no }}</title>
    <style>
        @page {
            margin: 12mm 15mm 16mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #18212b;
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 9.2pt;
            line-height: 1.28;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .letterhead td {
            padding: 0;
            vertical-align: middle;
        }

        .letterhead .logo-cell {
            width: 17mm;
            padding-right: 4mm;
            text-align: center;
        }

        .institution-logo {
            width: auto;
            height: 14mm;
        }

        .institution-name {
            color: #102f52;
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 13pt;
            font-weight: bold;
            letter-spacing: 0.35pt;
            text-transform: uppercase;
        }

        .office-name {
            margin-top: 1mm;
            color: #26394d;
            font-size: 9.5pt;
            font-weight: bold;
        }

        .header-rule {
            margin: 2.5mm 0 3mm;
            border-top: 1.2pt solid #102f52;
        }

        .document-title {
            margin: 0 0 2.5mm;
            color: #102f52;
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 14.5pt;
            font-weight: bold;
            letter-spacing: 0.5pt;
            text-align: center;
            text-transform: uppercase;
        }

        .reference-line {
            margin: 0 0 2.5mm;
            padding-bottom: 1.2mm;
            border-bottom: 0.4pt solid #aeb5bc;
            color: #59636d;
            font-size: 7.5pt;
            letter-spacing: 0.08pt;
            text-align: center;
        }

        .reference-line strong {
            color: #36414c;
            font-weight: bold;
        }

        .reference-separator {
            padding: 0 1.4mm;
            color: #9ba3aa;
        }

        .section-heading {
            margin: 0 0 1.2mm;
            padding-bottom: 0.8mm;
            border-bottom: 0.8pt solid #283d52;
            color: #102f52;
            font-size: 9pt;
            font-weight: bold;
            letter-spacing: 0.35pt;
            page-break-after: avoid;
            text-transform: uppercase;
        }

        .information {
            margin-bottom: 2mm;
        }

        .information td {
            padding: 0.45mm 2.5mm 0.75mm 0;
            text-align: left;
            vertical-align: top;
        }

        .information .field-label {
            display: block;
            margin-bottom: 0.15mm;
            color: #5c6670;
            font-size: 7.2pt;
            font-weight: bold;
            letter-spacing: 0.18pt;
            text-transform: uppercase;
        }

        .information .field-value {
            display: block;
            color: #202a34;
            word-wrap: break-word;
        }

        .information .half {
            width: 50%;
        }

        .request-statement {
            margin: 0 0 2mm;
            text-align: justify;
        }

        .items-table {
            margin-bottom: 2mm;
            page-break-inside: auto;
        }

        .items-table thead {
            display: table-header-group;
        }

        .items-table tr {
            page-break-inside: avoid;
        }

        .items-table th,
        .items-table td {
            padding: 1mm 1.4mm;
            border: 0.55pt solid #7d8791;
            vertical-align: top;
        }

        .items-table th {
            background: #e9edf1;
            color: #172c40;
            font-size: 7.8pt;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
        }

        .items-table .number {
            width: 7%;
            text-align: center;
        }

        .items-table .description {
            width: 48%;
        }

        .items-table .quantity {
            width: 12%;
            text-align: right;
        }

        .items-table .unit {
            width: 15%;
            text-align: center;
        }

        .items-table .location {
            width: 18%;
            text-align: center;
        }

        .certification {
            margin: 0;
        }

        .certification-text {
            margin: 0 0 2mm;
            text-align: justify;
        }

        .signature-area {
            width: 56%;
            margin: 1mm 0 1mm auto;
            page-break-inside: avoid;
            text-align: center;
        }

        .signature-intro {
            margin: 0 0 0.5mm;
            color: #46515c;
            font-size: 7.7pt;
            text-align: left;
        }

        .signature-space {
            height: 7mm;
        }

        .signature-image {
            display: block;
            width: auto;
            max-width: 60mm;
            height: auto;
            max-height: 9mm;
            margin: 0 auto -1mm;
        }

        .typed-signature {
            height: 7mm;
            color: #102f52;
            font-family: "DejaVu Serif", Georgia, serif;
            font-size: 11pt;
            font-style: italic;
            line-height: 7mm;
        }

        .signature-line {
            border-top: 0.7pt solid #252b31;
        }

        .signer-name {
            margin-top: 0.8mm;
            font-weight: bold;
            text-transform: uppercase;
        }

        .signer-role,
        .signer-designation,
        .signer-date {
            display: block;
            color: #384552;
            font-size: 8pt;
            line-height: 1.15;
        }

        .integrity-note {
            margin-top: 1mm;
            color: #69727b;
            font-size: 6.8pt;
        }

        .approvals {
            margin-top: 2mm;
            page-break-before: auto;
            page-break-inside: avoid;
        }

        .approval-signatures {
            table-layout: fixed;
        }

        .approval-signatures td {
            width: 50%;
            padding: 0.5mm 2.2mm 0;
            vertical-align: bottom;
            text-align: center;
        }

        .approval-caption {
            min-height: 5mm;
            color: #44505b;
            font-size: 7.2pt;
            font-weight: bold;
            letter-spacing: 0.16pt;
            text-transform: uppercase;
        }

        .approval-signature-space {
            height: 8mm;
        }

        .approval-signature {
            display: block;
            width: auto;
            max-width: 35mm;
            height: auto;
            max-height: 8mm;
            margin: 0 auto;
        }

        .approval-name {
            padding-top: 0.7mm;
            border-top: 0.65pt solid #333a40;
            font-size: 8pt;
            font-weight: bold;
            text-transform: uppercase;
        }

        .approval-role,
        .approval-date,
        .approval-routing,
        .approval-integrity {
            display: block;
            color: #4f5963;
            font-size: 6.8pt;
            line-height: 1.18;
        }

        .approval-role {
            min-height: 5mm;
        }

        .approval-integrity {
            margin-top: 0.5mm;
            color: #737b83;
            font-size: 6.2pt;
        }

        .approval-note {
            margin: 1.6mm 0 0;
            color: #3e4954;
            font-size: 7.7pt;
            text-align: center;
        }

        .document-footer {
            position: fixed;
            right: 31mm;
            bottom: -10.5mm;
            left: 0;
            padding-top: 1.5mm;
            border-top: 0.4pt solid #aab1b8;
            color: #69727b;
            font-size: 6.8pt;
        }
    </style>
</head>
<body>
    <div class="document-footer">
        Controlled digital document | SPMU-ACPMP | Official operational time: Asia/Manila
    </div>

    <table class="letterhead" aria-label="Institutional letterhead">
        <tr>
            <td class="logo-cell">
                <img class="institution-logo" src="{{ $logoDataUri }}" alt="Camarines Sur Polytechnic Colleges logo">
            </td>
            <td>
                <div class="institution-name">Camarines Sur Polytechnic Colleges</div>
                <div class="office-name">Supply and Property Management Unit</div>
            </td>
        </tr>
    </table>
    <div class="header-rule"></div>

    <h1 class="document-title">Borrowing Request Letter</h1>

    <p class="reference-line" aria-label="Document reference information">
        <strong>Request No.</strong> {{ $borrowingRequest->request_no }}
        <span class="reference-separator">|</span>
        <strong>Version</strong> {{ $version->version_no }}
        <span class="reference-separator">|</span>
        <strong>Status</strong> {{ $documentStatus }}
        <span class="reference-separator">|</span>
        <strong>Issued</strong> {{ $visibleGeneratedAt }}
    </p>

    <h2 class="section-heading">Borrower / Event Information</h2>
    <table class="information" aria-label="Borrower and event information">
        <tr>
            <td class="half">
                <span class="field-label">Borrower</span>
                <span class="field-value">{{ $borrowingRequest->borrower->full_name }}</span>
            </td>
            <td class="half">
                <span class="field-label">Employee No.</span>
                <span class="field-value">{{ $borrowingRequest->borrower->employee_no ?: 'N/A' }}</span>
            </td>
        </tr>
        <tr>
            <td class="half">
                <span class="field-label">Office / Department</span>
                <span class="field-value">{{ $borrowingRequest->accountableUnit->unit_name }}</span>
            </td>
            <td class="half">
                <span class="field-label">Represented Organization</span>
                <span class="field-value">{{ $version->student_organization ?: 'N/A' }}</span>
            </td>
        </tr>
        <tr>
            <td class="half">
                <span class="field-label">Purpose / Event</span>
                <span class="field-value">{{ $version->purpose_event }}</span>
            </td>
            <td class="half">
                <span class="field-label">Location</span>
                <span class="field-value">{{ $version->location }}</span>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="field-label">Event Details</span>
                <span class="field-value">{{ $version->event_details ?: 'N/A' }}</span>
            </td>
        </tr>
        <tr>
            <td class="half">
                <span class="field-label">Needed From</span>
                <span class="field-value">{{ $visibleNeededFrom }}</span>
            </td>
            <td class="half">
                <span class="field-label">Return Deadline</span>
                <span class="field-value">{{ $visibleReturnDueAt }}</span>
            </td>
        </tr>
        <tr>
            <td class="half">
                <span class="field-label">Program / Department and Year</span>
                <span class="field-value">{{ trim(($version->represented_program_department ?: '').' '.($version->represented_year_level ?: '')) ?: 'N/A' }}</span>
            </td>
            <td class="half">
                <span class="field-label">Use Classification</span>
                <span class="field-value">{{ $version->off_campus ? 'Off-campus barricade use' : 'On-campus use only' }}</span>
            </td>
        </tr>
    </table>

    <p class="request-statement">
        The undersigned respectfully requests the temporary use of the property and equipment listed below for the activity stated above, with accountability for the approved quantities and their return by the recorded deadline.
    </p>

    <h2 class="section-heading">Requested Items</h2>
    <table class="items-table" aria-label="Requested items">
        <thead>
            <tr>
                <th class="number">No.</th>
                <th class="description">Item / Description</th>
                <th class="quantity">Quantity</th>
                <th class="unit">Unit</th>
                <th class="location">Use Location</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($version->items as $item)
                <tr>
                    <td class="number">{{ $loop->iteration }}</td>
                    <td class="description">{{ $item->description_snapshot }}</td>
                    <td class="quantity">{{ $item->requested_quantity + 0 }}</td>
                    <td class="unit">{{ $item->unit_snapshot }}</td>
                    <td class="location">{{ str_replace('_', '-', $item->use_location) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <section class="certification">
        <h2 class="section-heading">Borrower Certification</h2>
        <p class="certification-text">
            I certify that the information and requested quantities stated in this letter are accurate. I accept accountability for the requested properties and their return on the approved Expected Return Date, subject to SPMU verification and actual physical issuance.
        </p>

        <div class="signature-area">
            <p class="signature-intro">Respectfully submitted by:</p>
            <div class="signature-space"></div>
            <div class="signature-line"></div>
            <div class="signer-name">{{ $borrowingRequest->borrower->full_name }}</div>
            <span class="signer-role">Accountable Borrower</span>
            <span class="signer-designation">{{ $borrowerDesignation }}</span>
            <span class="signer-date">Date: ____________________</span>
        </div>
    </section>

    <section class="approvals">
        <h2 class="section-heading">Required Physical Institutional Signatures</h2>
        <table class="approval-signatures" aria-label="Required physical signatures">
            <tr>
                <td class="approval-signature-block">
                    <div class="approval-caption">GSU</div>
                    <div class="approval-signature-space"></div>
                    <div class="approval-name">____________________________</div>
                    <span class="approval-role">Authorized GSU Signatory</span>
                    <span class="approval-date">Date: ____________________</span>
                </td>
                <td class="approval-signature-block">
                    <div class="approval-caption">VPAF</div>
                    <div class="approval-signature-space"></div>
                    <div class="approval-name">____________________________</div>
                    <span class="approval-role">Authorized VPAF Signatory / Noted By</span>
                    <span class="approval-date">Date: ____________________</span>
                </td>
            </tr>
        </table>
    </section>
</body>
</html>

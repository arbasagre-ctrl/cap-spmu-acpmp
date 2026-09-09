<style>
    @page { margin: 28px 34px 34px; }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        color: #111;
        font-family: Arial, DejaVu Sans, sans-serif;
        font-size: 9.3px;
        line-height: 1.35;
    }

    .official-document {
        position: relative;
        min-height: 720px;
        padding-bottom: 40px;
    }

    .document-header {
        width: 100%;
        border-collapse: collapse;
        margin: 0 0 12px;
        border-bottom: 1.2px solid #111;
    }

    .document-header td {
        border: 0;
        padding: 0 0 9px;
        vertical-align: middle;
    }

    .document-logo-cell {
        width: 68px;
        padding-right: 10px !important;
    }

    .document-logo {
        display: block;
        width: 54px;
        height: 54px;
        object-fit: contain;
    }

    .document-header-copy strong {
        display: block;
        color: #111;
        font-family: "Times New Roman", DejaVu Serif, serif;
        font-size: 14.5px;
        line-height: 1.08;
        letter-spacing: .2px;
    }

    .document-header-copy span {
        display: block;
        margin-top: 3px;
        color: #222;
        font-size: 8.4px;
        font-weight: 700;
    }

    .document-title {
        padding: 12px 0 10px;
        text-align: center;
    }

    .document-title h1 {
        margin: 0 0 6px;
        color: #111;
        font-family: "Times New Roman", DejaVu Serif, serif;
        font-size: 16.5px;
        line-height: 1.12;
        letter-spacing: .45px;
        text-transform: uppercase;
    }

    .document-meta {
        color: #222;
        font-size: 7.8px;
    }

    .document-meta strong {
        color: #111;
        font-size: 7.2px;
        text-transform: uppercase;
    }

    .meta-separator {
        display: inline-block;
        margin: 0 7px;
        color: #555;
    }

    .section-title {
        margin: 12px 0 6px;
        padding: 0 0 4px;
        border-bottom: 1px solid #222;
        color: #111;
        font-size: 8.8px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .2px;
    }

    .info-grid {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .info-grid td {
        width: 50%;
        padding: 2px 18px 7px 0;
        border: 0;
        vertical-align: top;
    }

    .info-grid td:nth-child(2) {
        padding-right: 0;
        padding-left: 8px;
    }

    .field-label {
        display: block;
        margin-bottom: 1px;
        color: #333;
        font-size: 7.2px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .field-value {
        display: block;
        color: #111;
        font-size: 9.5px;
        line-height: 1.3;
    }

    .document-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .document-table th,
    .document-table td {
        border: 1px solid #333;
        padding: 5px 7px;
        vertical-align: top;
    }

    .document-table th {
        background: transparent;
        color: #111;
        font-size: 7.5px;
        font-weight: 700;
        text-align: left;
        text-transform: uppercase;
    }

    .document-table td {
        color: #111;
        font-size: 8.8px;
    }

    .numeric { text-align: right !important; white-space: nowrap; }
    .center { text-align: center !important; }

    .total-box {
        margin: 8px 0 0 auto;
        width: 42%;
        border: 1px solid #222;
    }

    .total-box table {
        width: 100%;
        border-collapse: collapse;
    }

    .total-box td {
        padding: 6px 8px;
        border: 0;
    }

    .total-box .label {
        color: #111;
        font-size: 7.7px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .total-box .amount {
        color: #111;
        font-size: 11.5px;
        font-weight: 700;
        text-align: right;
    }

    /* Formal text block: intentionally plain, not a colored app/system callout. */
    .notice-box {
        margin-top: 8px;
        padding: 7px 9px;
        border: 1px solid #555;
        background: transparent;
    }

    .notice-box strong {
        display: block;
        margin-bottom: 2px;
        color: #111;
        font-size: 8.3px;
    }

    .notice-box p {
        margin: 0 0 3px;
        color: #111;
    }

    .notice-box p:last-child { margin-bottom: 0; }

    .warning-box {
        border-color: #555;
        background: transparent;
    }

    .warning-box strong { color: #111; }

    .decision-copy {
        margin: 0;
        color: #111;
        text-align: left;
    }

    .signature-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-top: 20px;
        page-break-inside: avoid;
    }

    .signature-table td {
        width: 50%;
        padding: 6px 18px 0;
        border: 0;
        text-align: center;
        vertical-align: top;
    }

    .signature-label {
        color: #111;
        font-size: 8px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .signature-space {
        height: 50px;
    }

    .signature-name {
        padding-top: 3px;
        border-top: 1px solid #111;
        color: #111;
        font-size: 9.5px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .signature-role {
        margin-top: 2px;
        color: #111;
        font-size: 8px;
    }

    .signature-date {
        margin-top: 2px;
        color: #333;
        font-size: 7.3px;
    }

    .document-footer {
        position: absolute;
        right: 0;
        bottom: 0;
        left: 0;
        padding-top: 6px;
        border-top: 1px solid #999;
        color: #333;
        font-size: 7px;
    }

    .document-footer table {
        width: 100%;
        border-collapse: collapse;
    }

    .document-footer td {
        border: 0;
        padding: 0;
        vertical-align: top;
    }

    .document-footer td:last-child { text-align: right; }

    .emphasis {
        color: #111;
        font-weight: 700;
    }

    .muted { color: #444; }
</style>

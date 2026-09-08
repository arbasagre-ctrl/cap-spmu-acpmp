<style>
/*
| Formal report document.
|
| One stylesheet for the on-screen preview and the printed page, so the sheet
| a reader reviews is the sheet that comes out of the printer. Institutional,
| not decorative: white ground, hairline rules, no radius, no shadow, no
| colour beyond the text itself.
*/
.doc-sheet {
    max-width: 1180px;
    margin: 0 auto;
    padding: 30px 34px 34px;
    background: #fff;
    color: #111;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
    line-height: 1.45;
}

.doc-header { display: grid; grid-template-columns: auto 1fr; align-items: center; column-gap: 12px; padding: 0 0 8px; border-bottom: 1px solid #222; }
.doc-header-seal { width: 58px; height: 58px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.doc-header-identity { min-width: 0; }
.doc-header-republic, .doc-header-address { display: block; font-size: 11px; line-height: 1.2; }
.doc-header-institution { display: block; font-size: 15px; font-weight: 700; line-height: 1.2; letter-spacing: .01em; }

.doc-title-block { margin: 18px 0 14px; text-align: center; }
.doc-title { margin: 0; font-size: 15px; font-weight: 700; letter-spacing: .04em; color: #111; }
.doc-period { margin: 3px 0 0; font-size: 11.5px; color: #333; }

.doc-meta { margin: 0 0 16px; padding: 0; display: grid; gap: 2px; }
.doc-meta > div { display: grid; grid-template-columns: 130px 1fr; column-gap: 8px; }
.doc-meta dt { font-size: 11.5px; color: #111; }
.doc-meta dt::after { content: " :"; }
.doc-meta dd { margin: 0; font-size: 11.5px; color: #111; }

.doc-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
.doc-table th {
    padding: 6px 7px;
    text-align: left;
    vertical-align: bottom;
    background: #f2f2f2;
    border: 1px solid #999;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: .02em;
    text-transform: uppercase;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.doc-table td { padding: 5px 7px; border: 1px solid #bbb; vertical-align: top; color: #111; }
.doc-table td.numeric, .doc-table th.numeric { text-align: right; font-variant-numeric: tabular-nums; }
.doc-table-empty { text-align: center; color: #555; padding: 18px 8px; }

.doc-summary { margin: 16px 0 0; }
.doc-summary-heading { margin: 0 0 6px; font-size: 11.5px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #111; }
.doc-summary dl { margin: 0; display: grid; gap: 2px; }
.doc-summary dl > div { display: grid; grid-template-columns: 220px auto; column-gap: 8px; }
.doc-summary dt { font-size: 11.5px; }
.doc-summary dt::after { content: " :"; }
.doc-summary dd { margin: 0; font-size: 11.5px; font-weight: 700; font-variant-numeric: tabular-nums; }

.doc-footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #999; }
.doc-footer p { margin: 0; font-size: 10px; color: #333; }

/* The table can outgrow the screen; on paper it is fitted by orientation. */
.doc-table-scroll { overflow-x: auto; }

@media print {
    .doc-sheet { max-width: none; margin: 0; padding: 0; }
    .doc-table-scroll { overflow: visible; }
    .doc-table thead { display: table-header-group; }
    .doc-table tr { break-inside: avoid; }
    .doc-summary, .doc-footer { break-inside: avoid; }
}
</style>

<style>
.borrower-request-detail { --borrower-blue: #0f62d6; width: 100%; min-width: 0; display: grid; gap: 18px; font-size: 13px; }
.borrower-request-detail [hidden] { display: none !important; }

/* Cards */
.borrower-detail-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(330px, 1fr)); align-items: start; gap: 18px; }
.borrower-request-detail .borrower-detail-card { min-width: 0; padding: 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); overflow: hidden; }
.borrower-request-detail .borrower-card-title { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 14px; margin: 0; padding: 17px 20px; border-bottom: 1px solid var(--border); color: var(--heading); font-size: 15px; font-weight: 750; }
.borrower-card-icon { display: grid; place-items: center; flex-shrink: 0; color: var(--borrower-blue); }
.borrower-card-note { margin-left: auto; color: var(--text-muted); font-size: 12px; font-weight: 600; }

/* Borrowing information */
.borrower-fact-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 0; padding: 18px 20px; }
.borrower-fact-grid > div { display: grid; gap: 5px; min-width: 0; padding-right: 20px; }
.borrower-fact-grid > div:nth-child(even) { padding-left: 22px; padding-right: 0; border-left: 1px solid var(--row-border); }
.borrower-fact-grid > div > span { color: var(--text-muted); font-size: 12px; }
.borrower-fact-grid > div > strong { color: var(--heading); font-size: 13.5px; font-weight: 700; line-height: 1.4; overflow-wrap: anywhere; }

/* Requested items */
.borrower-item-list { display: grid; }
.borrower-item-row { display: flex; align-items: baseline; justify-content: space-between; gap: 14px 20px; padding: 15px 20px; border-bottom: 1px solid var(--row-border); }
.borrower-item-row:last-child { border-bottom: 0; }
.borrower-item-row > div { min-width: 0; }
.borrower-item-row strong { display: block; color: var(--heading); font-size: 13.5px; font-weight: 700; line-height: 1.4; overflow-wrap: anywhere; }
.borrower-item-row small { display: block; margin-top: 2px; color: var(--text-muted); font-size: 11.5px; }
.borrower-item-quantity { flex-shrink: 0; text-align: right; }

/* Submitted documents */
.borrower-documents-scroll { width: 100%; min-width: 0; overflow-x: auto; }
.borrower-request-detail .borrower-documents-table { width: 100%; min-width: 640px; margin: 0; border-collapse: collapse; }
.borrower-request-detail .borrower-documents-table th { padding: 12px 20px; border-bottom: 1px solid var(--border); background: var(--surface-elevated); color: var(--text-muted); font-size: 10px; font-weight: 750; letter-spacing: .06em; text-transform: uppercase; text-align: left; white-space: nowrap; }
.borrower-request-detail .borrower-documents-table td { padding: 15px 20px; border-bottom: 1px solid var(--row-border); color: var(--text-secondary); font-size: 12.5px; vertical-align: middle; }
.borrower-documents-table tbody tr:last-child td { border-bottom: 0; }
.borrower-document-name { display: flex; align-items: center; gap: 11px; color: var(--heading); font-weight: 700; }
.borrower-document-name > .ui-icon { flex-shrink: 0; color: var(--borrower-blue); }
.borrower-document-view { color: var(--borrower-blue); font-weight: 700; }
.borrower-document-view:hover, .borrower-document-view:focus-visible { text-decoration: underline; }
.borrower-documents-empty { padding: 30px 20px; color: var(--text-muted); text-align: center; }

/* Request actions */
.borrower-request-detail .request-cancel-card { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 14px 20px; padding: 20px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.borrower-cancel-copy { display: grid; gap: 6px; min-width: 0; }
.borrower-request-detail .borrower-cancel-title { display: flex; align-items: center; gap: 10px; margin: 0; color: var(--heading); font-size: 15px; font-weight: 750; }
.borrower-request-detail .borrower-cancel-title > .ui-icon { flex-shrink: 0; color: var(--danger); }
.borrower-request-detail .borrower-cancel-copy p { margin: 0; color: var(--text-muted); font-size: 12.5px; line-height: 1.55; }
.borrower-request-detail .button.borrower-cancel-button { gap: 9px; min-height: 44px; padding: 12px 20px; border: 1px solid var(--danger-border); border-radius: 8px; background: var(--surface-elevated); color: var(--danger); font-size: 13.5px; font-weight: 700; white-space: nowrap; }
.borrower-request-detail .button.borrower-cancel-button:hover, .borrower-request-detail .button.borrower-cancel-button:focus-visible { color: #fff; background: var(--danger-action); border-color: var(--danger-action); }
.borrower-cancel-button .ui-icon { flex-shrink: 0; }

html[data-theme="dark"] .borrower-request-detail { --borrower-blue: #72b7f4; }
html[data-theme="dark"] .borrower-request-detail .button.borrower-cancel-button:hover,
html[data-theme="dark"] .borrower-request-detail .button.borrower-cancel-button:focus-visible { color: #fff; }

@media (max-width: 700px) {
    .borrower-fact-grid { grid-template-columns: minmax(0, 1fr); }
    .borrower-fact-grid > div:nth-child(even) { padding-left: 0; border-left: 0; }
    .borrower-request-detail .request-cancel-card { grid-template-columns: minmax(0, 1fr); }
    .borrower-request-detail .button.borrower-cancel-button { justify-content: center; }
}
</style>

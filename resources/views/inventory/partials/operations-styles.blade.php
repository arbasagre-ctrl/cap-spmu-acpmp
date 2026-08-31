<style>
.spmu-inventory { --inventory-blue: #0865df; width: 100%; min-width: 0; font-size: 13px; }
.spmu-inventory [hidden] { display: none !important; }
.spmu-inventory-card { padding: 17px 18px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.spmu-inventory-card label { min-width: 0; margin: 0; display: grid; gap: 8px; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.spmu-inventory-card input, .spmu-inventory-card select { width: 100%; min-height: 40px; border-radius: 7px; font-size: 12px; }

/* Availability window */
.spmu-inventory-availability { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto; align-items: end; gap: 14px 18px; margin-bottom: 14px; }
.spmu-inventory .button.spmu-inventory-check { min-height: 40px; padding: 11px 22px; border-radius: 7px; border-color: var(--inventory-blue); background: var(--inventory-blue); color: #fff; font-size: 12px; white-space: nowrap; }
.spmu-inventory .button.spmu-inventory-check:hover, .spmu-inventory .button.spmu-inventory-check:focus-visible { border-color: #0a56b8; background: #0a56b8; color: #fff; }

/* Search and category */
.spmu-inventory-filters { display: grid; grid-template-columns: minmax(0, 2.2fr) minmax(200px, 1fr); align-items: end; gap: 14px 18px; margin-bottom: 16px; }
.spmu-inventory-filters .search-input-shell input { padding-left: 36px; }

/* Result summary above the table */
.spmu-inventory-summary { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 18px; margin-bottom: 12px; padding: 0 2px; color: var(--text-secondary); font-size: 12px; }
.spmu-inventory-page-size { display: inline-flex; align-items: center; }
.spmu-inventory-page-size select { min-height: 38px; width: auto; min-width: 168px; padding: 8px 36px 8px 13px; border: 1px solid var(--border); border-radius: 7px; background-color: var(--surface-elevated); color: var(--heading); font-size: 12px; font-weight: 650; }

/* Table */
.spmu-inventory-table-wrap { border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.spmu-inventory-table-scroll { width: 100%; min-width: 0; overflow-x: auto; }
.spmu-inventory .spmu-inventory-table { width: 100%; min-width: 1140px; margin: 0; border-collapse: collapse; }
.spmu-inventory .spmu-inventory-table th { padding: 13px 14px; border-bottom: 1px solid var(--border); background: var(--table-heading-bg); color: var(--text-secondary); font-size: 10px; font-weight: 750; letter-spacing: .05em; line-height: 1.35; text-transform: uppercase; text-align: left; vertical-align: bottom; }
.spmu-inventory .spmu-inventory-table td { padding: 14px; border-bottom: 1px solid var(--row-border); color: var(--heading); font-size: 12px; line-height: 1.5; vertical-align: middle; }
.spmu-inventory-table tbody tr:last-child td { border-bottom: 0; }
.spmu-inventory-table tbody tr:hover:not(:has(.spmu-inventory-static-empty)) td { background: var(--row-hover); }
.spmu-inventory-table th.is-numeric, .spmu-inventory-table td.is-numeric { text-align: center; }
.spmu-inventory-table th.is-nowrap { white-space: nowrap; }
.spmu-inventory-table th:nth-child(1) { width: 9%; }
.spmu-inventory-table th:nth-child(2) { width: 20%; }
.spmu-inventory-table th:nth-child(7) { width: 10%; }
.spmu-inventory-table th:nth-child(8) { width: 10%; }
.spmu-inventory-table th:nth-child(9) { width: 11%; }
.spmu-inventory-table th:nth-child(10) { width: 11%; }
.spmu-inventory-id { display: inline-flex; align-items: center; justify-content: center; min-height: 28px; padding: 4px 9px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface-subtle); color: var(--heading); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 11px; font-weight: 700; white-space: nowrap; }
.spmu-inventory-item strong { display: block; color: var(--heading); font-size: 12px; font-weight: 700; line-height: 1.4; }
.spmu-inventory-item small { display: block; margin-top: 3px; color: var(--text-muted); font-size: 11px; }
.spmu-inventory-count { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
.spmu-inventory-states { display: grid; gap: 2px; color: var(--text-secondary); font-size: 11px; }
.spmu-inventory-states span.has-open { color: var(--warning); font-weight: 700; }
/* Good / suitable for borrowing reads as a positive condition. */
.spmu-inventory-table td[data-condition="SERVICEABLE"] .status-badge { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
.spmu-inventory-use { color: var(--text-secondary); font-size: 11px; line-height: 1.45; }
.spmu-inventory-use small { display: block; margin-top: 3px; color: var(--text-muted); }
.spmu-inventory-actions { display: flex; align-items: center; gap: 10px; flex-wrap: nowrap; white-space: nowrap; }
.spmu-inventory .spmu-inventory-actions .table-action { gap: 6px; min-height: 30px; padding: 5px 7px; color: var(--inventory-blue); font-size: 11px; }
.spmu-inventory.is-inventory-head .spmu-inventory-actions { flex-direction: column; align-items: flex-start; gap: 0; }
.spmu-inventory.is-inventory-head .spmu-inventory-actions .table-action { min-height: 26px; padding: 3px 7px; }
.spmu-inventory .spmu-inventory-actions .table-action:hover, .spmu-inventory .spmu-inventory-actions .table-action:focus-visible { background: var(--info-bg); }
.spmu-inventory-static-empty { padding: 34px 16px !important; color: var(--text-muted); text-align: center; }

/* Footer / pagination */
.spmu-inventory-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 18px; padding: 15px 18px; border-top: 1px solid var(--border); }
.spmu-inventory-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.spmu-inventory-pagination-group { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px 14px; margin-left: auto; }
.spmu-inventory-pagination { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.spmu-inventory-page { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-width: 36px; height: 38px; padding: 6px 11px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-elevated); color: var(--text-secondary); font: inherit; font-size: 12px; font-weight: 650; line-height: 1; cursor: pointer; }
.spmu-inventory-page:hover:not(:disabled):not(.is-active) { color: var(--inventory-blue); border-color: var(--inventory-blue); }
.spmu-inventory-page.is-active { color: #fff; background: var(--inventory-blue); border-color: var(--inventory-blue); font-weight: 750; }
.spmu-inventory-page:disabled { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.spmu-inventory-page .ui-icon { flex-shrink: 0; }
.spmu-inventory-page-previous { transform: rotate(180deg); }
.spmu-inventory-page-ellipsis { padding: 0 3px; color: var(--text-muted); font-size: 11px; }

/* No results */
.spmu-inventory-no-results { display: flex; flex-direction: column; align-items: center; gap: 9px; padding: 40px 20px; color: var(--text-muted); text-align: center; }
.spmu-inventory-no-results strong { color: var(--heading); font-size: 14px; }
.spmu-inventory-no-results p { margin: 0; font-size: 12px; }

html[data-theme="dark"] .spmu-inventory { --inventory-blue: #72b7f4; }
html[data-theme="dark"] .spmu-inventory .button.spmu-inventory-check { color: var(--navy-950); }
html[data-theme="dark"] .spmu-inventory .button.spmu-inventory-check:hover, html[data-theme="dark"] .spmu-inventory .button.spmu-inventory-check:focus-visible { border-color: #9acdf8; background: #9acdf8; color: var(--navy-950); }
html[data-theme="dark"] .spmu-inventory-page.is-active { color: var(--navy-950); }

@media (max-width: 900px) {
    .spmu-inventory-availability { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .spmu-inventory .button.spmu-inventory-check { grid-column: 1 / -1; justify-content: center; }
    .spmu-inventory-filters { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 620px) {
    .spmu-inventory-availability { grid-template-columns: minmax(0, 1fr); }
    .spmu-inventory-card { padding: 14px; }
    .spmu-inventory-summary { align-items: flex-start; }
    .spmu-inventory-page-size select { width: 100%; min-width: 0; }
    .spmu-inventory-footer { align-items: stretch; padding: 14px; }
    .spmu-inventory-pagination-group { align-items: stretch; justify-content: flex-start; margin-left: 0; }
    .spmu-inventory-pagination { margin-left: 0; }
}
</style>

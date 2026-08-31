<style>
.borrower-inventory { --inventory-blue: #0865df; width: 100%; min-width: 0; font-size: 13px; }
.borrower-inventory [hidden] { display: none !important; }
.borrower-inventory-card { padding: 17px 18px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.borrower-inventory-card label { min-width: 0; margin: 0; display: grid; gap: 8px; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.borrower-inventory-card input, .borrower-inventory-card select { width: 100%; min-height: 40px; border-radius: 7px; font-size: 12px; }

/* Search and category */
.borrower-inventory-filters { display: grid; grid-template-columns: minmax(0, 2.2fr) minmax(200px, 1fr); align-items: end; gap: 14px 18px; margin-bottom: 12px; }
.borrower-inventory-filters .search-input-shell input { padding-left: 36px; }

/* Availability note */
.borrower-inventory-helper { display: flex; align-items: flex-start; gap: 9px; margin: 0 0 14px; padding: 0 2px; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
.borrower-inventory-helper > .ui-icon { flex-shrink: 0; margin-top: 1px; color: var(--text-soft); }

/* Result summary above the table */
.borrower-inventory-summary { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 18px; margin-bottom: 12px; padding: 0 2px; color: var(--text-secondary); font-size: 12px; }
.borrower-inventory-page-size { display: inline-flex; align-items: center; }
.borrower-inventory-page-size select { min-height: 38px; width: auto; min-width: 160px; padding: 8px 36px 8px 13px; border: 1px solid var(--border); border-radius: 7px; background-color: var(--surface-elevated); color: var(--heading); font-size: 12px; font-weight: 650; }

/* Table */
.borrower-inventory-table-wrap { border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.borrower-inventory-table-scroll { width: 100%; min-width: 0; overflow-x: auto; }
.borrower-inventory .borrower-inventory-table { width: 100%; min-width: 940px; margin: 0; border-collapse: collapse; }
.borrower-inventory .borrower-inventory-table th { padding: 13px 14px; border-bottom: 1px solid var(--border); background: var(--table-heading-bg); color: var(--text-secondary); font-size: 10px; font-weight: 750; letter-spacing: .05em; line-height: 1.35; text-transform: uppercase; text-align: left; vertical-align: bottom; }
.borrower-inventory .borrower-inventory-table td { padding: 14px; border-bottom: 1px solid var(--row-border); color: var(--heading); font-size: 12px; line-height: 1.5; vertical-align: top; }
.borrower-inventory-table tbody tr:last-child td { border-bottom: 0; }
.borrower-inventory-table tbody tr:hover:not(:has(.borrower-inventory-static-empty)) td { background: var(--row-hover); }
.borrower-inventory-table th.is-numeric, .borrower-inventory-table td.is-numeric { text-align: center; }
.borrower-inventory-table th.col-number { width: 5%; }
.borrower-inventory-table th.col-id { width: 10%; }
.borrower-inventory-table th.col-description { width: 34%; }
.borrower-inventory-table th.col-category { width: 12%; }
.borrower-inventory-table th.col-unit { width: 9%; }
.borrower-inventory-table th.col-quantity { width: 10%; }
.borrower-inventory-table th.col-premises { width: 14%; }
.borrower-inventory-table td.col-number { color: var(--text-muted); font-variant-numeric: tabular-nums; }
.borrower-item-id { display: inline-flex; align-items: center; justify-content: center; min-height: 28px; padding: 4px 9px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface-subtle); color: var(--heading); font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; font-size: 11px; font-weight: 700; white-space: nowrap; }
.borrower-item-title { display: block; color: var(--heading); font-size: 12px; font-weight: 700; line-height: 1.4; }
.borrower-description { display: block; margin-top: 3px; color: var(--text-muted); font-size: 11px; line-height: 1.5; }
.borrower-description-more { padding: 0 0 0 4px; border: 0; background: transparent; color: var(--inventory-blue); font: inherit; font-size: 11px; font-weight: 700; cursor: pointer; }
.borrower-description-more:hover, .borrower-description-more:focus-visible { text-decoration: underline; }
.borrower-quantity { font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
.borrower-premises { display: inline-flex; align-items: center; padding: 4px 10px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-subtle); color: var(--text-secondary); font-size: 11px; font-weight: 650; white-space: nowrap; }
.borrower-inventory-static-empty { padding: 34px 16px !important; color: var(--text-muted); text-align: center; }

/* Footer / pagination */
.borrower-inventory-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 18px; padding: 15px 18px; border-top: 1px solid var(--border); }
.borrower-inventory-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.borrower-inventory-pagination-group { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px 14px; margin-left: auto; }
.borrower-inventory-pagination { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
.borrower-inventory-page { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-width: 36px; height: 38px; padding: 6px 11px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-elevated); color: var(--text-secondary); font: inherit; font-size: 12px; font-weight: 650; line-height: 1; cursor: pointer; }
.borrower-inventory-page:hover:not(:disabled):not(.is-active) { color: var(--inventory-blue); border-color: var(--inventory-blue); }
.borrower-inventory-page.is-active { color: #fff; background: var(--inventory-blue); border-color: var(--inventory-blue); font-weight: 750; }
.borrower-inventory-page:disabled { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.borrower-inventory-page .ui-icon { flex-shrink: 0; }
.borrower-inventory-page-previous { transform: rotate(180deg); }
.borrower-inventory-page-ellipsis { padding: 0 3px; color: var(--text-muted); font-size: 11px; }

/* No results */
.borrower-inventory-no-results { display: flex; flex-direction: column; align-items: center; gap: 9px; padding: 40px 20px; color: var(--text-muted); text-align: center; }
.borrower-inventory-no-results strong { color: var(--heading); font-size: 14px; }
.borrower-inventory-no-results p { margin: 0; font-size: 12px; }

html[data-theme="dark"] .borrower-inventory { --inventory-blue: #72b7f4; }
html[data-theme="dark"] .borrower-inventory-page.is-active { color: var(--navy-950); }

@media (max-width: 900px) {
    .borrower-inventory-filters { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 620px) {
    .borrower-inventory-card { padding: 14px; }
    .borrower-inventory-summary { align-items: flex-start; }
    .borrower-inventory-page-size select { width: 100%; min-width: 0; }
    .borrower-inventory-footer { align-items: flex-start; padding: 14px; }
    .borrower-inventory-pagination-group { margin-left: 0; }
}
</style>

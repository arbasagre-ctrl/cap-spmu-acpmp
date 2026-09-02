<style>
.reporting-detail .page-heading { margin-bottom: 24px; }
.reporting-detail .reports-navigation-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 16px; min-width: 0; }
.reporting-detail .reports-navigation-row .reporting-heading-actions { flex-shrink: 0; }
.reporting-detail .reporting-heading-actions .button { min-height: 43px; padding: 10px 18px; }
.reports-detail-page { display: grid; gap: 18px; min-width: 0; }
.reporting-workspace .report-generator-card { padding: 22px 24px; }
.report-generator-grid { display: grid; grid-template-columns: minmax(200px, 1.05fr) minmax(190px, 1.08fr) minmax(185px, 1.1fr) auto; align-items: end; gap: 24px; }
.reporting-workspace .report-generator-grid > label { display: grid; gap: 9px; min-width: 0; margin: 0; color: var(--heading); font-size: 12px; font-weight: 700; }
.reporting-workspace .report-generator-grid select { height: 45px; font-size: 13px; }
.reporting-detail .report-period-control > .ui-icon { left: auto; right: 13px; }
.reporting-detail .report-period-control select { appearance: none; background-image: none; padding-left: 13px; padding-right: 38px; }
.reporting-detail .report-period-context { min-height: 45px; font-size: 12px; }
.reporting-detail .report-period-context > .ui-icon { display: none; }
.reporting-workspace .report-generate-button { min-height: 45px; padding: 11px 20px; font-size: 13px; }
.report-period-helper, .report-description { margin: 16px 0 0; font-size: 11px; color: var(--report-muted); line-height: 1.5; }
.report-description { margin-top: 6px; }
.report-summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
.report-summary-grid.report-primary-summaries { grid-template-columns: repeat(3, minmax(0, 1fr)); }
.reporting-workspace .report-summary-item { display: flex; flex-direction: row; align-items: flex-start; gap: 22px; padding: 24px 22px; min-height: 126px; }
.report-summary-item .reporting-icon { width: 48px; height: 48px; border-radius: 10px; }
.report-summary-item strong { display: block; color: var(--heading); font-size: 30px; font-weight: 750; line-height: 1.15; }
.reporting-workspace .report-summary-item h2 { margin: 8px 0 6px; color: var(--heading); font-size: 13px; font-weight: 650; }
.report-summary-item p { margin: 0; font-size: 11px; color: var(--report-muted); line-height: 1.5; }
.report-additional-summary { display: flex; flex-wrap: wrap; gap: 8px 18px; font-size: 11px; color: var(--text-secondary); }
.report-additional-summary > span { padding: 6px 10px; border: 1px solid var(--report-line); border-radius: 5px; background: var(--surface-elevated); }
.report-additional-summary strong { margin-left: 6px; }
.reporting-workspace .report-output-card { padding: 24px; }
.report-output-header { display: flex; justify-content: space-between; align-items: center; gap: 18px; flex-wrap: wrap; margin-bottom: 24px; border: 0; background: transparent; padding: 0; }
.reporting-workspace .report-output-header .eyebrow { margin: 0 0 6px; color: var(--text-secondary); font-size: 10px; }
.report-output-header h2 { margin: 0 0 5px; font-size: 20px; font-weight: 650; }
.report-output-header p:not(.eyebrow) { margin: 0; color: var(--report-muted); font-size: 13px; }
.report-output-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.reporting-workspace .report-output-actions .button { min-height: 40px; padding: 9px 14px; }
.report-download-icon { transform: rotate(180deg); }
.report-output-body { padding: 0; min-width: 0; }
.report-table-scroll { overflow-x: auto; border: 1px solid var(--report-line); border-radius: 8px; }
.reporting-workspace .report-table { width: 100%; min-width: 960px; margin: 0; border-collapse: collapse; }
.reporting-workspace .report-table th { padding: 15px 10px; color: var(--heading); background: var(--surface-subtle); font-size: 10px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; white-space: nowrap; border-bottom: 1px solid var(--report-line); }
.reporting-workspace .report-table td { padding: 13px 10px; color: var(--heading); font-size: 12px; line-height: 1.65; border-bottom: 1px solid var(--report-line); vertical-align: middle; }
.reporting-workspace .report-table tr:last-child td { border-bottom: 0; }
.reporting-workspace .report-table .numeric { text-align: right; }
.report-table .status-badge { font-size: 10px; font-weight: 650; padding: 4px 8px; white-space: nowrap; }
.reporting-workspace .report-request-link, .reporting-workspace .report-table .table-action { color: var(--report-blue); text-decoration: none; font-weight: 700; }
.report-request-link { white-space: nowrap; }
.reporting-workspace .report-table .table-action { font-size: 11px; padding: 5px; }
.reporting-workspace .report-table .empty-state { padding: 30px 16px; text-align: center; color: var(--report-muted); }
.report-note { margin: 22px 0 0; color: var(--report-muted); font-size: 11px; line-height: 1.7; }
.report-section-subheading { margin: 20px 0 10px; font-size: 14px; }
@media (max-width: 1300px) {
    .report-generator-grid { grid-template-columns: minmax(180px, 1fr) minmax(180px, 1fr) minmax(170px, auto); gap: 16px; }
    .report-generator-grid .report-period-context { grid-column: 1 / 3; grid-row: 2; }
    .report-generator-grid .report-generate-button { grid-column: 3; grid-row: 1; }
    .reporting-workspace .report-summary-item { padding: 20px 16px; gap: 14px; }
}
@media (max-width: 900px) {
    .reporting-detail .page-heading { align-items: flex-start; flex-direction: column; gap: 16px; }
    .reporting-workspace .report-summary-item { gap: 12px; }
    .report-summary-item .reporting-icon { width: 36px; height: 36px; }
    .report-summary-item .reporting-icon svg { width: 21px; }
}
@media (max-width: 700px) {
    .report-generator-grid, .report-summary-grid.report-primary-summaries { grid-template-columns: 1fr; }
    .report-generator-grid .report-period-context, .report-generator-grid .report-generate-button { grid-column: auto; grid-row: auto; }
    .reporting-workspace .report-generator-card, .reporting-workspace .report-output-card { padding: 18px 15px; }
    .report-output-header { margin-bottom: 18px; }
    .reporting-workspace .report-summary-item { min-height: 100px; }
}
@media print {
    .reporting-detail .reports-navigation-row { display: none !important; }
    .reporting-workspace .report-table-scroll { overflow: visible; border-radius: 0; }
    .reporting-workspace .report-table { min-width: 0; width: 100%; table-layout: auto; }
    .reporting-workspace .report-table th, .reporting-workspace .report-table td { font-size: 9px; padding: 7px 5px; overflow-wrap: anywhere; white-space: normal; }
    .reporting-workspace .report-request-link, .reporting-workspace .status-badge { white-space: normal; }
    .reporting-workspace .report-output-card { padding: 14px; }
    .reporting-workspace .report-table thead { display: table-header-group; }
    .reporting-workspace .report-table tr { break-inside: avoid; }
}
</style>

<style>
/*
| Reports chrome.
|
| Reports is a document surface, not a dashboard. The hierarchy reads
| builder -> generated-report information -> actions -> records, and colour is
| used sparingly: statuses are legible as words before they are legible as
| tones.
*/

/* Report builder */
.report-builder-card { display: grid; gap: 16px; }
.report-builder-primary { display: grid; grid-template-columns: minmax(230px, 1.1fr) minmax(190px, .8fr) minmax(220px, .9fr); gap: 16px; align-items: start; }
.report-builder-field { display: grid; gap: 6px; margin: 0; font-size: 11.5px; font-weight: 750; letter-spacing: .02em; color: var(--text-secondary); }
.report-builder-field select { min-height: 42px; width: 100%; font-size: 13px; }
.report-resolved-period > p { display: flex; align-items: center; gap: 7px; min-height: 42px; margin: 0; padding: 0 12px; background: var(--surface-subtle); border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-resolved-period strong { color: var(--heading); font-size: 13px; font-weight: 750; }
.report-resolved-period .ui-icon { color: var(--text-soft); flex: 0 0 auto; }
.report-resolved-period small { color: var(--text-muted); font-size: 11px; font-weight: 500; }

.report-builder-actions { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.report-no-filters { color: var(--text-muted); font-size: 12px; }
.report-generate-button { min-height: 42px; }

.report-more-filters { flex: 1 1 420px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); }
.report-more-filters > summary { display: flex; align-items: center; gap: 8px; padding: 11px 14px; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); list-style: none; }
.report-more-filters > summary::-webkit-details-marker { display: none; }
.report-more-filters[open] > summary { border-bottom: 1px solid var(--border); }
.report-filter-count { display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--info-bg); color: var(--info); font-size: 11px; }
.report-more-filters-body { display: grid; gap: 12px; padding: 14px; }
.report-filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.report-more-filters-actions { display: flex; justify-content: flex-end; }

/* Generated report */
.report-metadata { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 0 0 18px; padding: 0; }
.report-metadata > div { padding: 12px 14px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); }
.report-metadata dt { font-size: 10px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; color: var(--text-muted); }
.report-metadata dd { margin: 5px 0 0; font-size: 12.5px; line-height: 1.45; color: var(--text); }
.report-metadata-count dd strong { font-size: 20px; font-weight: 800; color: var(--heading); font-variant-numeric: tabular-nums; }
.report-filter-warning { display: flex; align-items: flex-start; gap: 8px; margin: -6px 0 16px; color: var(--warning); font-size: 12px; }

/* Status badges: text first, tone second. */
.report-status-badge { display: inline-flex; align-items: center; min-height: 21px; padding: 2px 9px; border: 1px solid var(--neutral-border); border-radius: 999px; background: var(--neutral-bg); color: var(--neutral); font-size: 11px; font-weight: 700; white-space: nowrap; }
.report-status-badge.tone-positive { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
.report-status-badge.tone-attention { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
.report-status-badge.tone-critical { color: var(--danger); background: var(--danger-bg); border-color: var(--danger-border); }
.report-status-badge.tone-progress { color: var(--info); background: var(--info-bg); border-color: var(--info-border); }

.report-table-action-column { white-space: nowrap; }
.report-empty-state { margin: 0; padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }

/* Records footer and pagination */
.report-records-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 14px; }
.report-records-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.report-pagination { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-left: auto; }
.report-page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 4px 10px; color: var(--text-secondary); background: var(--surface-elevated); border: 1px solid var(--border); border-radius: 6px; text-decoration: none; font-size: 11.5px; font-weight: 700; }
.report-page-link.is-active { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.report-pagination a.report-page-link:hover { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.report-page-link[aria-disabled="true"] { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.report-page-ellipsis { padding: 0 2px; color: var(--text-muted); }

/* Contextual pointer to the dedicated Audit Trail module. */
.report-audit-link { margin: 18px 0 0; }
.report-audit-link a { display: inline-flex; align-items: center; gap: 4px; color: var(--interactive); font-size: 12px; font-weight: 700; text-decoration: none; }
.report-audit-link a:hover { text-decoration: underline; }

/* Reports vs Analytics boundary. */
.report-boundary-note { display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 14px 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); color: var(--text-muted); font-size: 12px; line-height: 1.55; }
.report-boundary-note .ui-icon { flex: 0 0 auto; color: var(--info); margin-top: 1px; }

@media (max-width: 1000px) { .report-builder-primary { grid-template-columns: 1fr; } }

@media print {
    .app-sidebar, .app-topbar, .report-builder-card, .report-output-actions,
    .report-more-filters, .report-generate-button, .report-records-footer,
    .report-pagination, .report-audit-link, .report-boundary-note { display: none !important; }
    .report-metadata { break-inside: avoid; }
    .report-output-card { border: 0 !important; box-shadow: none !important; }
}
</style>

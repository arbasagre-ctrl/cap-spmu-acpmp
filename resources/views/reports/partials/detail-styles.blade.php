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
.report-preview-empty { display: flex; align-items: center; gap: 13px; padding: 19px 20px; border: 1px dashed var(--report-line); border-radius: var(--radius); background: var(--surface-subtle); }
.report-preview-empty-icon { display: inline-flex; flex: 0 0 auto; align-items: center; justify-content: center; width: 36px; height: 36px; border: 1px solid color-mix(in srgb, var(--report-blue) 18%, var(--border)); border-radius: 10px; background: color-mix(in srgb, var(--report-blue) 7%, var(--surface-elevated)); color: var(--report-blue); }
.report-preview-empty p { margin: 0; color: var(--report-muted); font-size: 12px; line-height: 1.5; }
.report-preview-empty .report-preview-label { margin-bottom: 2px; color: var(--heading); }
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
| The builder is interface; everything below it is a document. The two are
| deliberately styled apart so the preview reads as a sheet of paper sitting
| on the application, not as another panel of it.
*/

/* Report builder */
.reporting-workspace .report-builder-card { display: grid; gap: 0; padding: 26px; border-radius: 12px; }
.report-builder-primary { display: grid; grid-template-columns: minmax(0, 30fr) minmax(0, 25fr) minmax(0, 27fr) auto; gap: 18px; align-items: start; margin-top: 14px; }
.report-builder-field { display: grid; gap: 7px; align-content: start; min-width: 0; margin: 0; font-size: 11.5px; font-weight: 750; letter-spacing: .02em; color: var(--text-secondary); }
.report-builder-field > span { min-height: 16px; line-height: 16px; }
.report-builder-field select { min-height: 42px; width: 100%; font-size: 13px; }
.report-resolved-period > p { display: flex; align-items: center; gap: 7px; min-height: 42px; margin: 0; padding: 0 12px; background: var(--surface-subtle); border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-resolved-period strong { color: var(--heading); font-size: 13px; font-weight: 750; white-space: nowrap; }
.report-resolved-period .ui-icon { color: var(--text-soft); flex: 0 0 auto; }
.report-resolved-period small { color: var(--text-muted); font-size: 11px; font-weight: 500; }

/* The primary action shares the top row, so opening the filters never moves it. */
.reporting-workspace .report-generate-button { min-height: 42px; padding: 10px 18px; font-size: 12.5px; }
.report-no-filters { display: block; margin: 18px 0 0; color: var(--text-muted); font-size: 12px; }

.report-more-filters { margin-top: 18px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); }
.report-more-filters > summary { display: flex; align-items: center; gap: 8px; padding: 11px 14px; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); list-style: none; }
.report-more-filters > summary::-webkit-details-marker { display: none; }
.report-more-filters[open] > summary { border-bottom: 1px solid var(--border); }
.report-more-filters-chevron { flex: 0 0 auto; margin-left: auto; color: var(--text-soft); transition: transform .18s ease; }
.report-more-filters[open] .report-more-filters-chevron { transform: rotate(180deg); }
.report-filter-count { display: inline-grid; place-items: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--info-bg); color: var(--info); font-size: 11px; }
.report-more-filters-body { padding: 16px 14px; background: var(--surface-elevated); border-radius: 0 0 var(--radius) var(--radius); }
.report-filter-grid { display: grid; grid-template-columns: repeat(var(--report-filter-columns, 3), minmax(0, 1fr)); gap: 16px; }
.report-builder-card .report-description { margin: 22px 0 0; }

/* Preview */
.report-preview-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin: 0 0 10px; }
.report-preview-label { margin: 0; color: var(--text-muted); font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
.report-preview-context { margin: 3px 0 0; color: var(--text-muted); font-size: 11px; line-height: 1.35; }
.report-filter-warning { display: flex; align-items: flex-start; gap: 8px; margin: 0 0 12px; color: var(--warning); font-size: 12px; }
.report-preview-sheet { display: flex; justify-content: center; overflow: auto; padding: clamp(12px, 2vw, 28px); border: 1px solid var(--border); background: #edf1f5; }
.report-preview-sheet .doc-sheet { width: 100%; min-width: min-content; margin: 0; box-shadow: 0 2px 9px rgba(13, 31, 49, .12); }
.report-preview-sheet--portrait .doc-sheet { max-width: 794px; }
.report-preview-sheet--landscape .doc-sheet { max-width: 1123px; }

/* Records footer and pagination */
.report-records-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-top: 14px; }
.report-records-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.report-records-label { display: grid; gap: 2px; }
.report-records-label strong { color: var(--heading); font-size: 11px; letter-spacing: .04em; text-transform: uppercase; }
.report-pagination { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-left: auto; }
.report-page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 32px; height: 32px; padding: 4px 10px; color: var(--text-secondary); background: var(--surface-elevated); border: 1px solid var(--border); border-radius: 6px; text-decoration: none; font-size: 11.5px; font-weight: 700; }
.report-page-link.is-active { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.report-pagination a.report-page-link:hover { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.report-page-link[aria-disabled="true"] { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.report-page-ellipsis { padding: 0 2px; color: var(--text-muted); }

.report-boundary-note { display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 14px 16px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-subtle); color: var(--text-muted); font-size: 12px; line-height: 1.55; }
.report-boundary-note .ui-icon { flex: 0 0 auto; color: var(--info); margin-top: 1px; }

/* Report Options dialog */
.report-options-dialog { width: min(560px, calc(100vw - 32px)); padding: 0; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface-elevated); color: var(--text); box-shadow: var(--shadow); }
.report-options-dialog::backdrop { background: rgba(7, 27, 53, .45); }
.report-options-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 13px 16px; border-bottom: 1px solid var(--border); }
.report-options-header h2 { margin: 0; font-size: 15px; font-weight: 750; color: var(--heading); }
.report-options-body { display: grid; gap: 12px; padding: 14px 16px; max-height: min(64vh, 520px); overflow-y: auto; }
.report-options-scope { display: grid; gap: 3px; margin: 0; padding: 11px 13px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); }
.report-options-scope > span { color: var(--heading); font-size: 13px; font-weight: 750; }
.report-options-scope small { color: var(--text-muted); font-size: 11.5px; }
.report-options-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
.report-options-field { display: grid; gap: 6px; margin: 0; font-size: 11.5px; font-weight: 700; color: var(--text-secondary); }
.report-options-field select, .report-options-field input { width: 100%; min-height: 40px; font-size: 13px; }
.report-options-custom-margins { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); }
.report-options-content { display: grid; gap: 9px; margin: 0; padding: 13px; border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-options-content legend { padding: 0 5px; color: var(--text-secondary); font-size: 11.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.report-options-advanced { border: 1px solid var(--border); border-radius: var(--radius-sm); }
.report-options-advanced > summary { padding: 11px 13px; cursor: pointer; font-size: 12.5px; font-weight: 700; color: var(--text-secondary); }
.report-options-advanced > div { display: grid; gap: 8px; padding: 0 13px 13px; }
.report-options-advanced p { margin: 0; color: var(--text-muted); font-size: 11.5px; }
.report-options-error { margin: 0; color: var(--danger); font-size: 12px; }
.report-options-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 12px 16px; border-top: 1px solid var(--border); }

@media (max-width: 1180px) {
    .report-builder-primary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .report-filter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
    .reporting-workspace .report-builder-card { padding: 18px 16px; }
    .report-builder-primary { grid-template-columns: minmax(0, 1fr); gap: 14px; }
    .report-filter-grid { grid-template-columns: minmax(0, 1fr); }
    .report-builder-submit > span { display: none; }
    .reporting-workspace .report-generate-button { width: 100%; justify-content: center; }
}

@media print {
    .app-sidebar, .app-topbar, .report-builder-card, .report-preview-bar,
    .report-more-filters, .report-generate-button, .report-records-footer,
    .report-pagination, .report-boundary-note,
    .report-options-dialog { display: none !important; }
    .report-preview-sheet { border: 0 !important; }
}
</style>

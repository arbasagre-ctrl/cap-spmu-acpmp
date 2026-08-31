<style>
.gate-pass-browser { --gate-pass-blue: #0866df; min-width: 0; font-size: 13px; }
.gate-pass-browser [hidden] { display: none !important; }
.gate-pass-browser .gate-pass-heading { margin: 0 0 22px; }
.gate-pass-browser .gate-pass-heading .eyebrow { margin-bottom: 8px; font-size: 10px; }
.gate-pass-browser .gate-pass-heading h1 { margin: 0 0 8px; font-size: clamp(25px, 2vw, 29px); }
.gate-pass-browser .gate-pass-heading p:not(.eyebrow) { max-width: none; font-size: 13px; }
.gate-pass-browser .card { min-width: 0; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.gate-pass-browser .gate-pass-empty-card { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 260px; padding: 24px; text-align: center; }
.gate-pass-empty-illustration { flex-shrink: 0; margin-bottom: 14px; }
.gate-pass-browser .gate-pass-empty-card h2 { margin: 0 0 8px; color: var(--heading); font-size: 16px; font-weight: 750; line-height: 1.5; }
.gate-pass-browser .gate-pass-empty-card p { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.7; }
.gate-pass-browser .gate-pass-toolbar { display: grid; grid-template-columns: minmax(0, 3fr) minmax(170px, 1fr) minmax(160px, 1fr); gap: 18px; align-items: end; margin-bottom: 6px; padding: 16px 18px; }
.gate-pass-toolbar label { min-width: 0; margin: 0; color: var(--text-secondary); font-size: 12px; }
.gate-pass-toolbar .search-input-shell, .gate-pass-toolbar select { margin-top: 3px; }
.gate-pass-toolbar input, .gate-pass-toolbar select { width: 100%; min-height: 40px; border-color: var(--border); border-radius: 6px; font-size: 12px; }
.gate-pass-browser .gate-pass-records-card { padding: 16px 16px 12px; }
.gate-pass-browser #gate-pass-records-title { margin: 0 0 14px; color: var(--heading); font-size: 15px; font-weight: 750; }
.gate-pass-table-wrap { min-width: 0; overflow-x: auto; }
.gate-pass-table { width: 100%; min-width: 900px; margin: 0; border-collapse: collapse; }
.gate-pass-table th { padding: 8px 10px; color: var(--heading); background: var(--surface-subtle); font-size: 11px; font-weight: 750; text-align: left; white-space: nowrap; }
.gate-pass-table td { padding: 11px 10px; border-bottom: 1px solid var(--row-border); color: var(--text-secondary); font-size: 12px; line-height: 1.5; vertical-align: middle; }
.gate-pass-table th:nth-child(1) { width: 15%; }
.gate-pass-table th:nth-child(2) { width: 17%; }
.gate-pass-table th:nth-child(3) { width: 23%; }
.gate-pass-table th:nth-child(4) { width: 12%; }
.gate-pass-table th:nth-child(5) { width: 20%; }
.gate-pass-table th:nth-child(6) { width: 13%; }
.gate-pass-request-link { color: var(--gate-pass-blue); font-weight: 750; white-space: nowrap; }
.gate-pass-request-link:hover { color: var(--interactive-hover); text-decoration: underline; }
.gate-pass-table td strong, .gate-pass-table td small { display: block; }
.gate-pass-table td strong { color: var(--heading); font-size: 12px; font-weight: 650; }
.gate-pass-table td small { margin-top: 2px; color: var(--text-muted); font-size: 11px; }
.gate-pass-destination { overflow-wrap: anywhere; }
.gate-pass-release-date { white-space: nowrap; }
.gate-pass-table .status-badge { padding: 4px 10px; border-color: transparent; font-size: 10px; white-space: nowrap; }
.gate-pass-row-actions { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; min-width: 136px; }
.gate-pass-browser .button.secondary { color: var(--gate-pass-blue); border-color: var(--gate-pass-blue); background: transparent; }
.gate-pass-browser .button.secondary:hover { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-browser .gate-pass-view { flex-shrink: 0; min-height: 32px; padding: 6px 11px; border-radius: 5px; font-size: 11px; white-space: nowrap; }
.gate-pass-more { flex: 0 0 auto; }
.gate-pass-more summary { display: grid; place-items: center; width: 24px; height: 32px; margin-left: auto; color: var(--text-secondary); border-radius: 5px; list-style: none; cursor: pointer; }
.gate-pass-more summary::-webkit-details-marker { display: none; }
.gate-pass-more summary:hover, .gate-pass-more[open] summary { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-more summary:focus-visible { outline: 2px solid var(--gate-pass-blue); outline-offset: 2px; }
/* Keep expanded actions in the row so a scrolling table cannot clip them. */
.gate-pass-more-links { display: grid; min-width: 150px; max-width: 210px; margin-top: 6px; padding: 5px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-elevated); }
.gate-pass-more-links a { padding: 7px 8px; border-radius: 4px; color: var(--text-secondary); font-size: 11px; }
.gate-pass-more-links a:hover, .gate-pass-more-links a:focus-visible { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-filter-empty td { padding: 28px 18px; text-align: center; }
.gate-pass-filter-empty p { margin: 6px 0 12px; color: var(--text-muted); }
.gate-pass-footer { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 1px 0; }
.gate-pass-footer p { margin: 0; color: var(--text-muted); font-size: 12px; }
.gate-pass-pagination, .gate-pass-page-numbers { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; }
.gate-pass-pagination { margin-left: auto; }
.gate-pass-browser .gate-pass-page { display: inline-flex; align-items: center; justify-content: center; width: 32px; min-width: 32px; height: 32px; padding: 4px; border: 1px solid var(--border); border-radius: 5px; color: var(--text-secondary); background: var(--surface-elevated); font-size: 12px; }
.gate-pass-browser .gate-pass-page.is-current { color: #fff; border-color: #0866df; background: #0866df; font-weight: 700; }
.gate-pass-browser .gate-pass-page:not(:disabled):not(.is-current):hover { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-browser .gate-pass-page:disabled { color: var(--text-soft); cursor: not-allowed; opacity: .5; transform: none; }
.gate-pass-previous-icon { transform: rotate(180deg); }
.gate-pass-page-ellipsis { padding: 0 2px; color: var(--text-muted); }
html[data-theme="dark"] .gate-pass-browser { --gate-pass-blue: #72b7f4; }
@media (max-width: 900px) {
    .gate-pass-browser .gate-pass-toolbar { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .gate-pass-toolbar > label:first-child { grid-column: 1 / -1; }
}
@media (max-width: 560px) {
    .gate-pass-browser .gate-pass-toolbar { grid-template-columns: minmax(0, 1fr); padding: 14px; }
    .gate-pass-browser .gate-pass-records-card { padding: 14px; }
    .gate-pass-browser .gate-pass-empty-card { padding: 24px 16px; }
    .gate-pass-empty-card br { display: none; }
}
</style>

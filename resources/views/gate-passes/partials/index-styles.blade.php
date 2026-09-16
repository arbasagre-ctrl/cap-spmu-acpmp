<style>
.gate-pass-browser {
    --gate-pass-blue: #0866df;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    color: var(--text);
    font-size: 13px;
}
.gate-pass-browser [hidden] { display: none !important; }
.gate-pass-browser .gate-pass-heading { margin: 0 0 20px; }
.gate-pass-browser .gate-pass-heading .eyebrow { margin-bottom: 9px; font-size: 10px; letter-spacing: .1em; }
.gate-pass-browser .gate-pass-heading h1 { margin: 0 0 8px; font-size: clamp(25px, 2vw, 29px); }
.gate-pass-browser .gate-pass-heading p:not(.eyebrow) { max-width: none; margin: 0; font-size: 13px; line-height: 1.5; }
.gate-pass-browser .card { min-width: 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }

.gate-pass-browser .gate-pass-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 2.4fr) minmax(0, .95fr) minmax(0, .9fr);
    gap: 14px;
    align-items: end;
    padding: 16px 18px;
    margin-bottom: 10px;
}
.gate-pass-toolbar label {
    min-width: 0;
    margin: 0;
    color: var(--heading);
    font-size: 12px;
    font-weight: 650;
}
.gate-pass-toolbar .search-input-shell,
.gate-pass-toolbar select { margin-top: 7px; }
.gate-pass-browser .gate-pass-toolbar input,
.gate-pass-browser .gate-pass-toolbar select {
    width: 100%;
    min-height: 40px;
    font-size: 12px;
}

.gate-pass-browser .gate-pass-records-card { padding: 16px 18px 4px; }
.gate-pass-records-heading { margin-bottom: 14px; }
.gate-pass-browser .gate-pass-records-heading h2 { margin: 0 0 6px; color: var(--heading); font-size: 17px; font-weight: 750; }
.gate-pass-records-heading p { margin: 0; color: var(--text-muted); font-size: 12px; }
.gate-pass-table-wrap { width: 100%; min-width: 0; overflow-x: auto; }
.gate-pass-table { width: 100%; min-width: 900px; margin: 0; border-collapse: collapse; }
.gate-pass-table th {
    padding: 10px 8px;
    color: var(--heading);
    background: var(--table-heading-bg);
    font-size: 11px;
    font-weight: 750;
    text-align: left;
    white-space: nowrap;
}
.gate-pass-table td {
    padding: 11px 8px;
    border-bottom: 1px solid var(--row-border);
    color: var(--text-secondary);
    font-size: 12px;
    line-height: 1.5;
    vertical-align: middle;
}
.gate-pass-table tr:last-child td { border-bottom: 0; }
.gate-pass-table th:nth-child(1) { width: 15%; }
.gate-pass-table th:nth-child(2) { width: 17%; }
.gate-pass-table th:nth-child(3) { width: 23%; }
.gate-pass-table th:nth-child(4) { width: 12%; }
.gate-pass-table th:nth-child(5) { width: 20%; }
.gate-pass-table th:nth-child(6) { width: 13%; }
.gate-pass-request-link { color: var(--gate-pass-blue); font-weight: 750; white-space: nowrap; text-decoration: none; }
.gate-pass-request-link:hover { color: var(--interactive-hover); text-decoration: underline; }
.gate-pass-table td strong, .gate-pass-table td small { display: block; }
.gate-pass-table td strong { color: var(--heading); font-size: 12px; font-weight: 650; }
.gate-pass-table td small { margin-top: 1px; color: var(--text-muted); font-size: 12px; }
.gate-pass-destination { overflow-wrap: anywhere; }
.gate-pass-release-date { white-space: nowrap; }
.gate-pass-table .status-badge { padding: 4px 10px; border: 0; border-radius: 999px; font-size: 10px; white-space: nowrap; }
.gate-pass-row-actions { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 136px; }
.gate-pass-browser .button.secondary { color: var(--gate-pass-blue); border-color: var(--gate-pass-blue); background: var(--surface-elevated); }
.gate-pass-browser .button.secondary:not(:disabled):hover,
.gate-pass-browser .button.secondary:not(:disabled):focus-visible { color: #fff; border-color: #0866df; background: #0866df; }
.gate-pass-browser .gate-pass-view { flex-shrink: 0; min-height: 35px; padding: 7px 12px; border-radius: 6px; font-size: 11px; white-space: nowrap; }

.gate-pass-more { position: relative; flex: 0 0 auto; }
.gate-pass-more summary { display: grid; place-items: center; width: 28px; height: 35px; margin-left: auto; color: var(--text-secondary); border-radius: 6px; list-style: none; cursor: pointer; }
.gate-pass-more summary::-webkit-details-marker { display: none; }
.gate-pass-more summary:hover, .gate-pass-more[open] summary { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-more summary:focus-visible { outline: 2px solid var(--gate-pass-blue); outline-offset: 2px; }
.gate-pass-more-links {
    position: fixed;
    z-index: 1600;
    display: grid;
    min-width: 170px;
    max-width: 220px;
    margin: 0;
    padding: 5px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-md, 0 10px 28px rgba(15, 23, 42, .16));
}
.gate-pass-more:not([open]) .gate-pass-more-links { display: none; }
.gate-pass-more-links a { padding: 7px 8px; border-radius: 4px; color: var(--text-secondary); font-size: 11px; }
.gate-pass-more-links a:hover, .gate-pass-more-links a:focus-visible { color: var(--gate-pass-blue); background: var(--info-bg); }

.gate-pass-filter-empty td { padding: 26px 12px; text-align: center; }
.gate-pass-filter-empty strong { color: var(--heading); font-size: 14px; }
.gate-pass-filter-empty p { margin: 6px 0 12px; color: var(--text-muted); }
.gate-pass-footer { display: flex; justify-content: flex-end; padding: 13px 0 9px; }
.gate-pass-pagination, .gate-pass-page-numbers { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; }
.gate-pass-pagination { margin-left: auto; }
.gate-pass-browser .gate-pass-page {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    min-width: 34px;
    height: 35px;
    padding: 4px;
    border: 1px solid var(--border);
    border-radius: 5px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    font-size: 12px;
}
.gate-pass-browser .gate-pass-page.is-current { color: #fff; border-color: #0866df; background: #0866df; font-weight: 700; }
.gate-pass-browser .gate-pass-page:not(:disabled):not(.is-current):hover { color: var(--gate-pass-blue); background: var(--info-bg); }
.gate-pass-browser .gate-pass-page:disabled { color: var(--text-soft); cursor: not-allowed; opacity: .5; transform: none; }
.gate-pass-previous-icon { transform: rotate(180deg); }
.gate-pass-page-ellipsis { padding: 0 2px; color: var(--text-muted); }

.gate-pass-browser .gate-pass-empty-card { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 218px; padding: 24px 18px 30px; text-align: center; }
.gate-pass-empty-illustration { display: block; flex-shrink: 0; width: 86px; height: 86px; margin: 0 0 12px; }
.gate-pass-browser .gate-pass-empty-card h2 { margin: 0 0 7px; color: var(--heading); font-size: 16px; font-weight: 750; line-height: 1.5; }
.gate-pass-browser .gate-pass-empty-card p { margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.5; }

html[data-theme="dark"] .gate-pass-browser { --gate-pass-blue: #72b7f4; }
@media (max-width: 1000px) {
    .gate-pass-browser .gate-pass-toolbar { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) minmax(0, .8fr); }
}
@media (max-width: 700px) {
    .gate-pass-browser .gate-pass-toolbar { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .gate-pass-toolbar > label:first-child { grid-column: 1 / -1; }
    .gate-pass-browser .gate-pass-records-card { padding-right: 12px; padding-left: 12px; }
}
@media (max-width: 430px) {
    .gate-pass-browser .gate-pass-toolbar { grid-template-columns: minmax(0, 1fr); }
    .gate-pass-toolbar > label:first-child { grid-column: auto; }
}
</style>

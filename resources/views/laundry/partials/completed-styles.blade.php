<style>
.completed-laundry {
    --completed-blue: #0866df;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    color: var(--text);
    font-size: 13px;
}
.completed-laundry [hidden] { display: none !important; }
.completed-laundry .completed-laundry-heading { column-gap: 20px; margin-bottom: 20px; }
.completed-laundry-heading .eyebrow { margin-bottom: 9px; font-size: 10px; letter-spacing: .1em; }
.completed-laundry .completed-laundry-heading h1 { margin: 0 0 8px; font-size: clamp(25px, 2vw, 29px); line-height: 1.25; }
.completed-laundry .completed-laundry-heading > div > p:last-child { margin: 0; font-size: 13px; line-height: 1.5; }
.completed-laundry .button.completed-laundry-back {
    flex-shrink: 0;
    gap: 8px;
    min-height: 40px;
    padding: 10px 16px;
    border: 1px solid var(--completed-blue);
    border-radius: 7px;
    background: var(--surface-elevated);
    color: var(--completed-blue);
    font-size: 12px;
    white-space: nowrap;
}
.completed-laundry-back .ui-icon { flex-shrink: 0; }
.completed-laundry .card { border-radius: 10px; }

.completed-laundry .completed-laundry-filter-card {
    display: grid;
    grid-template-columns: minmax(0, 2.4fr) minmax(0, .95fr) minmax(0, .9fr);
    gap: 14px;
    align-items: end;
    padding: 16px 18px;
    margin-bottom: 10px;
}
.completed-laundry-filter-card label {
    min-width: 0;
    margin: 0;
    color: var(--heading);
    font-size: 12px;
    font-weight: 650;
}
.completed-laundry-filter-card .search-input-shell,
.completed-laundry-filter-card select { margin-top: 7px; }
.completed-laundry .completed-laundry-filter-card input,
.completed-laundry .completed-laundry-filter-card select {
    width: 100%;
    min-height: 40px;
    font-size: 12px;
}
.completed-laundry-filter-scope {
    grid-column: 1 / -1;
    margin: -2px 0 0;
    color: var(--text-muted);
    font-size: 11px;
}

.completed-laundry .completed-laundry-card { padding: 16px 18px 4px; }
.completed-laundry-cases-heading { margin-bottom: 14px; }
.completed-laundry .completed-laundry-cases-heading h2 { margin: 0 0 6px; font-size: 17px; font-weight: 750; }
.completed-laundry-cases-heading p { margin: 0; color: var(--text-muted); font-size: 12px; }
.completed-laundry-table-wrap { width: 100%; min-width: 0; overflow-x: auto; }
.completed-laundry .completed-laundry-table { width: 100%; min-width: 840px; margin: 0; border-collapse: collapse; }
.completed-laundry .completed-laundry-table th {
    padding: 10px 8px;
    color: var(--heading);
    background: var(--table-heading-bg);
    font-size: 11px;
    font-weight: 750;
    letter-spacing: 0;
    text-align: left;
    text-transform: none;
    white-space: nowrap;
}
.completed-laundry .completed-laundry-table td {
    padding: 11px 8px;
    border-bottom: 1px solid var(--row-border);
    color: var(--text-secondary);
    font-size: 12px;
    line-height: 1.5;
    vertical-align: middle;
}
.completed-laundry-table tr:last-child td { border-bottom: 0; }
.completed-laundry-table th:nth-child(1) { width: 18%; }
.completed-laundry-table th:nth-child(2) { width: 22%; }
.completed-laundry-table th:nth-child(3) { width: 10%; }
.completed-laundry-table th:nth-child(4) { width: 18%; }
.completed-laundry-table th:nth-child(5) { width: 18%; }
.completed-laundry-table th:nth-child(6) { width: 14%; }
.completed-laundry-case-id { white-space: nowrap; }
.completed-laundry-date time, .completed-laundry-date time > span { display: block; }
.completed-laundry-date time { white-space: nowrap; }
.completed-laundry-outcomes { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 6px; }
.completed-laundry-badge {
    display: inline-block;
    width: fit-content;
    max-width: 160px;
    padding: 4px 8px;
    border: 1px solid transparent;
    border-radius: 6px;
    font-size: 10px;
    line-height: 1.5;
}
.completed-laundry-badge.is-available { color: #087a34; background: #eef9f1; border-color: #d2ecd9; }
.completed-laundry-badge.is-maintenance { color: #c1610a; background: #fff7ed; border-color: #ffe2bf; }
.completed-laundry-badge.is-neutral { color: var(--text-muted); background: var(--surface-subtle); border-color: var(--border); }
.completed-laundry .button.completed-laundry-view {
    min-height: 35px;
    padding: 7px 12px;
    border-radius: 6px;
    font-size: 11px;
    white-space: nowrap;
}
.completed-laundry .button.secondary { transition: color 160ms ease, background-color 160ms ease, border-color 160ms ease; }
.completed-laundry .button.ui-pressable.secondary:not(:disabled):hover,
.completed-laundry .button.ui-pressable.secondary:not(:disabled):focus-visible {
    color: #fff !important;
    border-color: #0866df !important;
    background: #0866df !important;
}
.completed-laundry-footer { display: flex; justify-content: flex-end; padding: 13px 0 9px; }
.completed-laundry-pagination { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.completed-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 35px;
    padding: 5px 10px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 5px;
    text-decoration: none;
    font-size: 11px;
    line-height: 1;
}
.completed-page-link.is-active { color: #fff; background: #0866df; border-color: #0866df; font-weight: 750; }
.completed-laundry-pagination a.completed-page-link:hover,
.completed-laundry-pagination a.completed-page-link:focus-visible { color: #fff; background: #0866df; border-color: #0866df; }
.completed-laundry-pagination a.completed-page-link:focus-visible { outline: 2px solid var(--completed-blue); outline-offset: 2px; }
.completed-page-link[aria-disabled="true"] { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.completed-page-previous { transform: rotate(180deg); }
.completed-page-ellipsis { color: var(--text-muted); padding: 0 3px; }
.completed-laundry-no-results { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 26px 12px; color: var(--text-muted); text-align: center; }
.completed-laundry-no-results strong { color: var(--heading); font-size: 14px; }
.completed-laundry-no-results p { margin: 0; }

.completed-laundry .completed-laundry-empty {
    display: flex;
    min-height: 260px;
    align-items: center;
    justify-content: center;
    padding: 32px 20px;
    text-align: center;
}
.completed-laundry-empty-content { display: flex; flex-direction: column; align-items: center; max-width: 540px; }
.completed-laundry-empty-illustration { display: block; width: 100%; max-width: 160px; height: auto; margin-bottom: 16px; }
.completed-laundry .completed-laundry-empty h2 { margin: 0 0 8px; font-size: 16px; font-weight: 750; line-height: 1.5; }
.completed-laundry-empty p { margin: 0 0 20px; color: var(--text-secondary); font-size: 13px; line-height: 1.6; }
.completed-laundry-archive-note {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 14px;
    padding: 11px 14px;
    border: 1px solid var(--info-border);
    border-radius: 7px;
    color: var(--text-muted);
    background: var(--info-bg);
}
.completed-laundry-archive-note > .ui-icon { flex-shrink: 0; color: var(--completed-blue); }
.completed-laundry-archive-note p { margin: 0; font-size: 11px; line-height: 1.5; }

html[data-theme="dark"] .completed-laundry { --completed-blue: #72b7f4; }
html[data-theme="dark"] .completed-laundry-empty-illustration { opacity: .82; }
html[data-theme="dark"] .completed-laundry-badge.is-available { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
html[data-theme="dark"] .completed-laundry-badge.is-maintenance { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }

@media (max-width: 1000px) {
    .completed-laundry .completed-laundry-filter-card { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) minmax(0, .8fr); }
}
@media (max-width: 700px) {
    .completed-laundry .completed-laundry-heading { align-items: stretch; gap: 12px; }
    .completed-laundry-heading > .completed-laundry-back { align-self: flex-end; }
    .completed-laundry .completed-laundry-filter-card { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .completed-laundry-filter-card > label:first-child { grid-column: 1 / -1; }
    .completed-laundry .completed-laundry-card { padding-right: 12px; padding-left: 12px; }
    .completed-laundry-archive-note { align-items: flex-start; }
}
@media (max-width: 430px) {
    .completed-laundry .completed-laundry-filter-card { grid-template-columns: minmax(0, 1fr); }
    .completed-laundry-filter-card > label:first-child { grid-column: auto; }
    .completed-laundry-pagination { gap: 6px; }
    .completed-page-link { min-width: 30px; height: 33px; padding: 5px 8px; }
}
@media (prefers-reduced-motion: reduce) {
    .completed-laundry .button.secondary { transition: none; }
}
</style>

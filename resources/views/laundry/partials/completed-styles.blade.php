<style>
.completed-laundry { --completed-blue: #0865df; width: 100%; min-width: 0; max-width: 100%; font-size: 13px; }
.completed-laundry [hidden] { display: none !important; }
.completed-laundry .completed-laundry-heading { align-items: flex-end; gap: 22px; margin-bottom: 24px; }
.completed-laundry-heading .eyebrow { margin-bottom: 12px; font-size: 10px; letter-spacing: .09em; }
.completed-laundry .completed-laundry-heading h1 { margin: 0 0 10px; font-size: clamp(25px, 2.2vw, 30px); line-height: 1.25; }
.completed-laundry .completed-laundry-heading > div > p:last-child { margin: 0; font-size: 13px; line-height: 1.5; }
.completed-laundry .button.completed-laundry-back { flex-shrink: 0; gap: 9px; min-height: 40px; padding: 10px 14px; border: 1px solid var(--completed-blue); border-radius: 7px; background: var(--surface-elevated); color: var(--completed-blue); font-size: 12px; white-space: nowrap; }
.completed-laundry-back .ui-icon { flex-shrink: 0; }
.completed-laundry .completed-laundry-empty { display: flex; min-height: clamp(360px, 52vh, 510px); align-items: center; justify-content: center; padding: 40px 22px; border-radius: 9px; text-align: center; }
.completed-laundry-empty-content { display: flex; flex-direction: column; align-items: center; max-width: 540px; }
.completed-laundry-empty-illustration { display: block; width: 100%; max-width: 220px; height: auto; margin-bottom: 18px; }
.completed-laundry .completed-laundry-empty h2 { margin: 0 0 11px; font-size: 17px; font-weight: 750; line-height: 1.5; }
.completed-laundry-empty p { margin: 0 0 24px; color: var(--text-secondary); font-size: 13px; line-height: 1.8; }
.completed-laundry .completed-laundry-card { padding: 18px 16px 16px; border-radius: 9px; }
.completed-laundry-toolbar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px 20px; margin-bottom: 17px; }
.completed-laundry #completed-laundry-cases-title { display: flex; align-items: center; gap: 10px; margin: 0; font-size: 15px; font-weight: 750; }
.completed-laundry-list-icon { display: grid; place-items: center; width: 31px; height: 31px; flex-shrink: 0; color: var(--completed-blue); background: var(--info-bg); border-radius: 6px; }
.completed-laundry-filters { display: grid; grid-template-columns: minmax(190px, 1.6fr) minmax(160px, 1fr); align-items: center; gap: 8px; max-width: 520px; flex: 1 1 360px; }
.completed-laundry-filters label { min-width: 0; margin: 0; }
.completed-laundry .completed-laundry-search input[type="search"] { width: 100%; min-height: 37px; padding: 9px 36px 9px 11px; border-color: var(--border); border-radius: 6px; font-size: 11px; }
.completed-laundry-search .search-input-icon { left: auto; right: 11px; width: 16px; height: 16px; }
.completed-laundry-outcome-filter { position: relative; }
.completed-laundry-outcome-filter > .ui-icon { position: absolute; top: 50%; left: 10px; color: var(--completed-blue); pointer-events: none; transform: translateY(-50%); }
.completed-laundry .completed-laundry-outcome-filter select { width: 100%; min-height: 37px; padding: 9px 34px 9px 31px; border-color: var(--border); border-radius: 6px; color: var(--completed-blue); font-size: 11px; font-weight: 650; }
.completed-laundry-filter-scope { margin: -5px 0 12px; color: var(--text-muted); font-size: 11px; }
.completed-laundry-table-wrap { width: 100%; min-width: 0; overflow-x: auto; border: 1px solid var(--border); border-radius: 7px; }
.completed-laundry .completed-laundry-table { width: 100%; min-width: 740px; margin: 0; }
.completed-laundry .completed-laundry-table th { padding: 12px 13px; border-bottom: 1px solid var(--border); background: var(--table-heading-bg); color: var(--text-secondary); font-size: 10px; font-weight: 750; letter-spacing: .015em; text-transform: uppercase; white-space: nowrap; }
.completed-laundry .completed-laundry-table td { padding: 15px 13px; border-bottom: 1px solid var(--row-border); color: var(--heading); font-size: 12px; line-height: 1.6; vertical-align: middle; }
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
.completed-laundry-badge { display: inline-block; width: fit-content; max-width: 145px; padding: 4px 8px; border: 1px solid transparent; border-radius: 6px; font-size: 11px; line-height: 1.6; }
.completed-laundry-badge.is-available { color: #087a34; background: #eef9f1; border-color: #d2ecd9; }
.completed-laundry-badge.is-maintenance { color: #c1610a; background: #fff7ed; border-color: #ffe2bf; }
.completed-laundry-badge.is-neutral { color: var(--text-muted); background: var(--surface-subtle); border-color: var(--border); }
.completed-laundry .button.completed-laundry-view { min-height: 33px; padding: 7px 10px; border-color: var(--completed-blue); border-radius: 6px; color: var(--completed-blue); background: var(--surface-elevated); font-size: 11px; white-space: nowrap; }
.completed-laundry .button.secondary { transition: color 160ms ease, background-color 160ms ease, border-color 160ms ease; }
.completed-laundry .button.ui-pressable.secondary:not(:disabled):hover,
.completed-laundry .button.ui-pressable.secondary:not(:disabled):focus-visible { color: #fff !important; border-color: #0865df !important; background: #0865df !important; }
.completed-laundry-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; padding: 18px 3px 0; }
.completed-laundry-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.completed-laundry-pagination { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 8px; margin-left: auto; }
.completed-page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 35px; padding: 5px 10px; color: var(--text-secondary); background: var(--surface-elevated); border: 1px solid var(--border); border-radius: 5px; text-decoration: none; font-size: 11px; line-height: 1; }
.completed-page-link.is-active { color: #fff; background: #0865df; border-color: #0865df; font-weight: 750; }
.completed-laundry-pagination a.completed-page-link:hover,
.completed-laundry-pagination a.completed-page-link:focus-visible { color: #fff; background: #0865df; border-color: #0865df; }
.completed-laundry-pagination a.completed-page-link:focus-visible { outline: 2px solid var(--completed-blue); outline-offset: 2px; }
.completed-page-link[aria-disabled="true"] { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.completed-page-previous { transform: rotate(180deg); }
.completed-page-ellipsis { color: var(--text-muted); padding: 0 3px; }
.completed-laundry-no-results { display: flex; flex-direction: column; align-items: center; gap: 9px; padding: 28px 16px; color: var(--text-muted); text-align: center; }
.completed-laundry-no-results strong { color: var(--heading); }
.completed-laundry-no-results p { margin: 0; }
.completed-laundry-archive-note { display: flex; align-items: center; gap: 11px; margin-top: 26px; padding: 12px 15px; border: 1px solid var(--info-border); border-radius: 7px; color: var(--completed-blue); background: var(--info-bg); }
.completed-laundry-archive-note > .ui-icon { flex-shrink: 0; }
.completed-laundry-archive-note p { margin: 0; font-size: 12px; line-height: 1.6; }
html[data-theme="dark"] .completed-laundry { --completed-blue: #72b7f4; }
html[data-theme="dark"] .completed-laundry-empty-illustration { opacity: .82; }
html[data-theme="dark"] .completed-laundry-badge.is-available { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
html[data-theme="dark"] .completed-laundry-badge.is-maintenance { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
@media (max-width: 900px) {
    .completed-laundry-toolbar { align-items: stretch; }
    .completed-laundry-filters { flex-basis: 100%; max-width: none; }
}
@media (max-width: 700px) {
    .completed-laundry .completed-laundry-heading { align-items: stretch; gap: 14px; margin-bottom: 18px; }
    .completed-laundry-heading > .completed-laundry-back { align-self: flex-end; }
    .completed-laundry .completed-laundry-card { padding: 14px 12px; }
    .completed-laundry .completed-laundry-empty { min-height: 360px; padding: 32px 15px; }
    .completed-laundry-filters { grid-template-columns: minmax(0, 1.5fr) minmax(140px, 1fr); }
    .completed-laundry-footer { align-items: flex-start; gap: 12px; }
    .completed-laundry-archive-note { align-items: flex-start; margin-top: 20px; }
}
@media (max-width: 450px) {
    .completed-laundry-filters { grid-template-columns: minmax(0, 1fr); }
    .completed-laundry-pagination { gap: 6px; }
    .completed-page-link { min-width: 30px; height: 33px; padding: 5px 8px; }
}
@media (prefers-reduced-motion: reduce) {
    .completed-laundry .button.secondary { transition: none; }
}
</style>

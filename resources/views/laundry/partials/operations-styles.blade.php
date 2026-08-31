<style>
.laundry-operations {
    --laundry-blue: #0866df;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    color: var(--text);
    font-size: 13px;
}
.laundry-operations [hidden] { display: none !important; }
.laundry-operations .laundry-operations-heading { align-items: flex-end; gap: 20px; margin-bottom: 20px; }
.laundry-operations-heading .eyebrow { margin-bottom: 9px; font-size: 10px; letter-spacing: .1em; }
.laundry-operations .laundry-operations-heading h1 { margin: 0 0 8px; font-size: clamp(25px, 2vw, 29px); }
.laundry-operations .laundry-operations-heading > div > p:last-child { font-size: 13px; line-height: 1.5; }
.laundry-operations .button.laundry-completed-link { flex-shrink: 0; min-height: 40px; padding: 10px 16px; border-radius: 7px; }
.laundry-operations .card { border-radius: 10px; }
.laundry-operations .laundry-flow-card {
    width: 100%;
    min-width: 0;
    max-width: 100%;
    padding: 0;
    margin: 0 0 14px;
    overflow: hidden;
}
.laundry-flow-body { padding: 18px 20px 12px; }
.laundry-operations #laundry-flow-title { margin: 0; font-size: 16px; font-weight: 750; }
.laundry-flow-rail {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    width: 100%;
    min-width: 0;
    margin: 20px 0 0;
    padding: 0;
    list-style: none;
}
.laundry-flow-step { position: relative; display: flex; min-width: 0; flex-direction: column; align-items: center; gap: 13px; text-align: center; }
.laundry-flow-step:not(:last-child)::after {
    content: "";
    position: absolute;
    top: 28px;
    left: calc(50% + 38px);
    width: calc(100% - 76px);
    height: 2px;
    background: #3885ec;
}
.laundry-flow-step.is-emphasized::after { background: var(--border-strong); }
.laundry-flow-marker { position: relative; display: grid; place-items: center; width: 58px; height: 58px; flex-shrink: 0; border: 1px solid var(--border); border-radius: 50%; color: var(--text-muted); background: var(--surface-subtle, #f8fafc); }
.laundry-flow-number { position: absolute; bottom: -3px; right: -3px; display: grid; place-items: center; width: 22px; height: 22px; border: 2px solid var(--surface-elevated); border-radius: 50%; background: #1169cb; color: #fff; font-size: 11px; font-weight: 750; line-height: 1; }
.laundry-flow-step > strong { max-width: 170px; padding: 0 4px; color: var(--text-muted); font-size: 12px; font-weight: 750; line-height: 1.5; }
.laundry-flow-step.is-emphasized .laundry-flow-marker { border-color: var(--laundry-blue); background: var(--info-bg); color: var(--navy-800); box-shadow: 0 0 0 3px color-mix(in srgb, var(--info-bg) 55%, transparent); }
.laundry-flow-step.is-emphasized > strong { color: var(--laundry-blue); }
.laundry-flow-step.is-internal .laundry-flow-marker { color: var(--heading); }
.laundry-flow-step.is-internal .laundry-flow-number { background: #8595ab; }
.laundry-flow-annotation-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin-top: 13px; }
.laundry-flow-annotation {
    position: relative;
    grid-column: 3;
    justify-self: center;
    width: min(100%, 228px);
    margin: 0;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
    font-size: 12px;
    line-height: 1.5;
}
.laundry-flow-annotation::before { content: ""; position: absolute; top: -6px; left: calc(50% - 6px); width: 10px; height: 10px; border-top: 1px solid var(--border); border-left: 1px solid var(--border); background: var(--surface-elevated); transform: rotate(45deg); }
.laundry-flow-annotation strong { color: var(--heading); }
.laundry-flow-footer { display: flex; align-items: flex-start; gap: 9px; margin: 0; padding: 12px 18px; border-top: 1px solid var(--row-border); color: var(--text-muted); font-size: 11px; line-height: 1.5; }
.laundry-flow-footer > .ui-icon { flex-shrink: 0; }
.laundry-operations .laundry-toolbar { display: grid; grid-template-columns: minmax(0, 2.4fr) minmax(0, .95fr) minmax(0, .9fr); gap: 14px; align-items: end; padding: 16px 18px; margin-bottom: 10px; }
.laundry-toolbar label { min-width: 0; margin: 0; color: var(--heading); font-size: 12px; }
.laundry-toolbar .search-input-shell, .laundry-toolbar select { margin-top: 7px; }
.laundry-operations .laundry-toolbar input, .laundry-operations .laundry-toolbar select { min-height: 40px; width: 100%; font-size: 12px; }
.laundry-filter-scope { grid-column: 1 / -1; margin: -2px 0 0; color: var(--text-muted); font-size: 11px; }
.laundry-operations .laundry-cases-card { padding: 16px 18px 4px; }
.laundry-cases-heading { margin-bottom: 14px; }
.laundry-operations .laundry-cases-heading h2 { margin: 0 0 6px; font-size: 17px; font-weight: 750; }
.laundry-cases-heading p { margin: 0; color: var(--text-muted); font-size: 12px; }
.laundry-table-wrap { width: 100%; min-width: 0; overflow-x: auto; }
.laundry-operations .laundry-cases-table { width: 100%; min-width: 980px; margin: 0; }
.laundry-operations .laundry-cases-table th { padding: 10px 8px; color: var(--heading); background: var(--table-heading-bg); font-size: 11px; font-weight: 750; letter-spacing: 0; text-transform: none; white-space: nowrap; }
.laundry-operations .laundry-cases-table td { padding: 11px 8px; border-bottom: 1px solid var(--row-border); font-size: 12px; line-height: 1.5; vertical-align: middle; }
.laundry-cases-table th:first-child { width: 15%; }
.laundry-cases-table th:nth-child(2) { width: 16%; }
.laundry-cases-table th:nth-child(3) { width: 17%; }
.laundry-cases-table th:nth-child(4) { width: 14%; }
.laundry-cases-table th:nth-child(5) { width: 19%; }
.laundry-cases-table td > strong, .laundry-cases-table td > small { display: block; }
.laundry-cases-table td > strong { color: var(--heading); font-size: 12px; font-weight: 650; }
.laundry-cases-table td > small { margin-top: 1px; font-size: 12px; }
.laundry-request-link { color: var(--laundry-blue); font-weight: 750; text-decoration: none; }
.laundry-request-link:hover { text-decoration: underline; }
.laundry-returned-date { white-space: nowrap; }
.laundry-operations .laundry-cases-table .status-badge { border: 0; border-radius: 999px; padding: 4px 10px; font-size: 10px; white-space: nowrap; }
.laundry-operations [data-status="FOR_LAUNDRY"] .status-badge { color: #a74a05; background: #fff0e2; }
.laundry-operations [data-status="TURNED_OVER_TO_LAUNDRY"] .status-badge { color: #075cd7; background: #e6efff; }
.laundry-operations .button.laundry-view-link { min-height: 35px; padding: 7px 12px; border-radius: 6px; font-size: 11px; white-space: nowrap; }
.laundry-operations .button.secondary:not(:disabled):hover,
.laundry-operations .button.secondary:not(:disabled):focus-visible { color: #fff; border-color: #0866df; background: #0866df; }
.laundry-pagination { padding: 13px 0 9px; }
.laundry-operations .laundry-empty-card { display: flex; min-height: 218px; flex-direction: column; align-items: center; justify-content: center; padding: 24px 18px 30px; text-align: center; }
.laundry-empty-illustration { display: block; flex-shrink: 0; width: 86px; height: 86px; margin: 0 0 12px; }
.laundry-operations .laundry-empty-card h2 { margin: 0 0 7px; font-size: 16px; font-weight: 750; }
.laundry-empty-card p { margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.5; }
.laundry-filter-empty { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 26px 12px; text-align: center; color: var(--text-muted); }
.laundry-filter-empty strong { color: var(--heading); font-size: 14px; }
.laundry-filter-empty p { margin: 0; }
html[data-theme="dark"] .laundry-operations { --laundry-blue: #72b7f4; }
html[data-theme="dark"] .laundry-flow-step.is-emphasized .laundry-flow-marker { color: var(--laundry-blue); }
html[data-theme="dark"] .laundry-operations [data-status="FOR_LAUNDRY"] .status-badge { color: var(--warning); background: var(--warning-bg); }
html[data-theme="dark"] .laundry-operations [data-status="TURNED_OVER_TO_LAUNDRY"] .status-badge { color: var(--info); background: var(--info-bg); }
@media (max-width: 1000px) {
    .laundry-operations .laundry-toolbar { grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr) minmax(0, .8fr); }
    .laundry-flow-annotation { font-size: 11px; }
}
@media (max-width: 700px) {
    .laundry-operations .laundry-operations-heading { align-items: stretch; gap: 12px; }
    .laundry-completed-link { align-self: flex-end; }
    .laundry-flow-body { padding: 16px 12px 12px; }
    .laundry-flow-marker { width: 46px; height: 46px; }
    .laundry-flow-marker > svg { width: 23px; height: 23px; }
    .laundry-flow-number { width: 20px; height: 20px; font-size: 10px; }
    .laundry-flow-step:not(:last-child)::after { top: 23px; left: calc(50% + 28px); width: calc(100% - 56px); }
    .laundry-flow-step > strong { font-size: 11px; }
    .laundry-flow-annotation { grid-column: 2 / 5; width: min(100%, 248px); justify-self: end; }
    .laundry-flow-annotation::before { left: 48%; }
    .laundry-operations .laundry-toolbar { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; }
    .laundry-toolbar > label:first-child { grid-column: 1 / -1; }
    .laundry-operations .laundry-cases-card { padding-right: 12px; padding-left: 12px; }
}
@media (max-width: 400px) {
    .laundry-flow-marker { width: 40px; height: 40px; }
    .laundry-flow-step:not(:last-child)::after { top: 20px; left: calc(50% + 24px); width: calc(100% - 48px); }
    .laundry-flow-step > strong { padding: 0 2px; font-size: 10px; }
    .laundry-flow-annotation { grid-column: 1 / -1; justify-self: center; width: 100%; }
    .laundry-flow-annotation::before { left: 62%; }
    .laundry-operations .laundry-toolbar { grid-template-columns: minmax(0, 1fr); }
}
</style>

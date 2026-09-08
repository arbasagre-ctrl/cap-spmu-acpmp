{{-- Reference Option B: presentation scoped to the Head's oversight workspace. --}}
<style>
.accountability-option-b {
    --heading: #071d49;
    --text-primary: #123661;
    --text-secondary: #365e8a;
    --text-muted: #476c96;
    --text-soft: #7194ba;
    --border: #d5e3f1;
    --interactive: #087cff;
    display: flex;
    flex-direction: column;
    min-height: calc(100dvh - var(--topbar-height) - 52px);
    color: var(--text-primary);
}
html:not([data-theme="dark"]) .app-main:has(.accountability-option-b) {
    max-width: none;
    padding: 22px 20px 12px;
    background: linear-gradient(115deg, #f3f8fc, #f7fafc);
}
.accountability-option-b .accountability-page-heading { position: relative; margin-bottom: 12px; }
.accountability-option-b .accountability-page-heading .eyebrow { margin: 0 0 3px; font-size: 11px; letter-spacing: .07em; }
.accountability-option-b .accountability-page-heading h1 { margin: 0 0 4px; font-size: 26px; font-weight: 750; letter-spacing: -.65px; }
.accountability-option-b .accountability-page-heading p:not(.eyebrow) { font-size: 12px; margin-bottom: 0; }
.accountability-option-b .accountability-asof { position: absolute; top: 0; right: 0; margin: 0; font-size: 10.5px !important; font-weight: 400; }
.accountability-option-b .accountability-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 20px; }
.accountability-option-b .accountability-kpi-card {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 0;
    min-height: 124px;
    padding: 12px 14px 10px;
    border: 1px solid var(--border);
    border-top: 1px solid var(--kpi-accent);
    border-radius: 9px;
    box-shadow: none;
}
.accountability-option-b .accountability-kpi-card::before,
.accountability-option-b .accountability-kpi-card::after { display: none; }
.accountability-option-b .accountability-kpi-card .kpi-icon { align-self: flex-start; width: 28px; height: 28px; margin: 0 0 8px; border: 0; border-radius: 7px; }
.accountability-option-b .accountability-kpi-card .kpi-icon svg { width: 21px; height: 21px; }
.accountability-option-b .accountability-kpi-card .kpi-value { margin: 0 0 2px; color: var(--heading); font-size: 25px; line-height: 1.1; }
.accountability-option-b .accountability-kpi-card .kpi-label { font-size: 11px; font-weight: 750; line-height: 1.4; }
.accountability-option-b .accountability-kpi-card small { margin: 6px 0 0; max-width: 100%; font-size: 10px; font-weight: 400; line-height: 1.4; }
.accountability-option-b .kpi-accent-warning { --kpi-accent: #ffb540; --kpi-icon-bg: #fff3d9; background: #fffdfa; border-color: #f4dfbb; border-top-color: #ffb540; }
.accountability-option-b .kpi-accent-warning .kpi-icon { color: #ff9400; }
.accountability-option-b .kpi-accent-info { --kpi-accent: #087cff; --kpi-icon-bg: #d5eeff; background: #f1f9ff; border-color: #b6dcff; border-top-color: #087cff; }
.accountability-option-b .kpi-accent-info .kpi-icon { color: #007aff; }
.accountability-option-b .kpi-accent-success { --kpi-accent: #18b9a3; --kpi-icon-bg: #d8faf0; background: #f5fffc; border-color: #c6e9e2; }
.accountability-option-b .kpi-accent-success .kpi-icon { color: #00a58d; }
.accountability-option-b .kpi-accent-restriction { --kpi-accent: #8445ff; --kpi-icon-bg: #f0e8ff; background: #fcfaff; border-color: #e1d1ff; border-top-color: #8445ff; }
.accountability-option-b .kpi-accent-restriction .kpi-icon { color: #792cff; }
.accountability-option-b .accountability-kpi-card.is-active { background: #e8f5ff; border-color: #99ceff; border-top-color: #087cff; }
.accountability-option-b .accountability-tabs { flex-shrink: 0; gap: 3px; margin: 0 -5px 9px; padding: 0; border: 0; border-radius: 0; background: transparent; }
.accountability-option-b .accountability-tab { flex: 1 0 auto; justify-content: center; gap: 8px; min-height: 35px; padding: 0 10px; border-radius: 5px; font-size: 11px; }
.accountability-option-b .accountability-tab .ui-icon { color: #356291; }
.accountability-option-b .accountability-tab.is-active { background: linear-gradient(120deg, #0087ff, #0674f9); border-color: #087cff; box-shadow: 0 3px 8px #087cff22; color: white; }
.accountability-option-b .accountability-tab.is-active .ui-icon { color: white; }
.accountability-option-b > .content-area { margin-top: 0; margin-bottom: 16px; }
.accountability-option-b .accountability-cases-card { border: 1px solid var(--border); border-radius: 9px; padding: 0 8px 8px; background: #fff; box-shadow: none; overflow: visible; }
.accountability-option-b .accountability-cases-head { min-height: 64px; gap: 10px; padding: 14px 0 16px; border-bottom: 0; }
.accountability-option-b .accountability-cases-head h2 { gap: 8px; color: var(--heading); font-size: 16px; font-weight: 750; letter-spacing: -.35px; }
.accountability-option-b .accountability-count-chip { min-width: 23px; padding: 2px 7px; color: white; background: #087cff; font-size: 11px; line-height: 16px; }
.accountability-option-b .accountability-cases-tools { gap: 10px; }
.accountability-option-b .accountability-search input { width: 210px; font-size: 11px; border-radius: 6px; }
.accountability-option-b .accountability-filter-button { display: grid; place-items: center; border-radius: 6px; }
.accountability-option-b .accountability-filter-menu[hidden] { display: none; }
.accountability-option-b .accountability-cases-table { border: 0; border-radius: 6px; }
.accountability-option-b .accountability-cases-table table { width: 100%; min-width: 540px; table-layout: auto; }
.accountability-option-b .accountability-cases-table th { padding: 12px 9px; background: #f0f5f9; color: #153e6c; font-size: 10.5px; line-height: 1.55; letter-spacing: 0; text-transform: none; white-space: normal; border-bottom: 1px solid #e8f0f7; }
.accountability-option-b .accountability-cases-table th:first-child { width: 24%; }
.accountability-option-b .accountability-cases-table th:nth-child(3) { width: 14%; }
.accountability-option-b .accountability-case-row > td { padding: 11px 9px; color: var(--text-primary); font-size: 10.5px; white-space: nowrap; }
.accountability-option-b .accountability-case-row > td:first-child { min-width: 0; white-space: normal; }
.accountability-option-b .accountability-case-ref { color: #111; font-size: 10.5px; letter-spacing: -.2px; }
.accountability-option-b .accountability-case-borrower { margin-top: 3px; font-size: 11px; color: var(--text-primary); }
.accountability-option-b .accountability-status-pill { gap: 4px; padding: 3px 6px; border: 0; border-radius: 8px; font-size: 9px; line-height: 1.5; }
.accountability-option-b .accountability-status-pill.status-warning,
.accountability-option-b .accountability-case-row[data-status="OVERDUE"] .accountability-status-pill { color: #9c4e00; background: #ffedca; }
.accountability-option-b .accountability-case-row[data-status="OVERDUE"] .accountability-status-pill::before { background: #f28a00; }
.accountability-option-b .accountability-cases-table .is-numeric { text-align: left; }
.accountability-option-b .accountability-case-detail-row > td { padding: 0 7px 8px; }
.accountability-option-b .accountability-detail-panel { gap: 12px; padding: 13px 14px; border-radius: 8px; }
.accountability-option-b .accountability-detail-panel.is-warning { border-color: #ffe5b1; background: #fff5df; }
.accountability-option-b .accountability-detail-panel.is-warning > .ui-icon { color: #f59a0b; background: #ffedbe; border-radius: 50%; width: 22px; height: 22px; padding: 2px; }
.accountability-option-b .accountability-detail-panel.is-warning strong,
.accountability-option-b .accountability-detail-panel.is-warning p { color: #6a3500; }
.accountability-option-b .accountability-detail-panel strong { font-size: 11px; }
.accountability-option-b .accountability-detail-panel p { font-size: 11px; }
.accountability-option-b .accountability-detail-panel small { margin-top: 7px; font-size: 10px; }
.accountability-option-b > .accountability-scope-note { margin: auto 8px 0; padding: 13px 16px; border: 0; border-radius: 8px; background: #dcebff; }
.accountability-option-b .accountability-scope-note > .ui-icon { box-sizing: content-box; margin-top: 2px; padding: 3px; border-radius: 50%; background: #c1e1ff; color: #0085ff; }
.accountability-option-b .accountability-scope-note strong { font-size: 11px; }
.accountability-option-b .accountability-scope-note p { margin-top: 4px; font-size: 11px; }
.accountability-option-b .accountability-scope-note a { color: inherit; text-decoration: none; }
.accountability-option-b .accountability-scope-note a:hover { text-decoration: underline; }
@media (min-width: 1600px) {
    .accountability-option-b .accountability-kpi-card { min-height: 140px; }
    .accountability-option-b .accountability-case-row > td,
    .accountability-option-b .accountability-case-ref { font-size: 12px; }
}
@media (max-width: 1100px) {
    .accountability-option-b .accountability-asof { position: static; margin-top: 6px; }
    .accountability-option-b .accountability-page-heading { flex-wrap: wrap; gap: 0; }
}
@media (max-width: 720px) {
    .accountability-option-b .accountability-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .accountability-option-b .accountability-cases-head { flex-direction: column; align-items: stretch; }
    .accountability-option-b .accountability-search input { width: 100%; }
    .accountability-option-b .accountability-case-detail-row > td { padding-right: 0; padding-left: 0; }
    .accountability-option-b .accountability-detail-panel { left: 7px; width: calc(100cqi - 14px); }
    .accountability-option-b > .accountability-scope-note { margin-top: 24px; }
}
@media (min-width: 721px) and (max-width: 900px) {
    .accountability-option-b .accountability-detail-panel { position: static; width: auto; }
}
html[data-theme="dark"] .accountability-option-b {
    --heading: #edf5ff; --text-primary: #dbe8f8; --text-secondary: #b6cbe4;
    --text-muted: #a0b8d4; --text-soft: #8ba8c9; --border: #31465e;
}
html[data-theme="dark"] .accountability-option-b .accountability-kpi-card,
html[data-theme="dark"] .accountability-option-b .accountability-cases-card { background: #152335; border-color: #31465e; }
html[data-theme="dark"] .accountability-option-b .accountability-kpi-card.is-active,
html[data-theme="dark"] .accountability-option-b .accountability-scope-note { background: #193752; }
html[data-theme="dark"] .accountability-option-b .accountability-cases-table th { background: #1d3045; color: #c9e0ff; border-color: #31465e; }
html[data-theme="dark"] .accountability-option-b .accountability-case-ref { color: #edf5ff; }
html[data-theme="dark"] .accountability-option-b .accountability-detail-panel.is-warning { background: #372c19; border-color: #685125; }
html[data-theme="dark"] .accountability-option-b .accountability-detail-panel.is-warning strong,
html[data-theme="dark"] .accountability-option-b .accountability-detail-panel.is-warning p { color: #f9d89d; }
</style>

<style>
.reporting-workspace { --report-blue: #065df0; --report-green: #008647; --report-line: var(--border); --report-muted: var(--text-muted); min-width: 0; font-size: 13px; }
.reporting-workspace *, .reporting-workspace *::before, .reporting-workspace *::after { box-sizing: border-box; }
.reporting-workspace .page-heading { margin-bottom: 16px; }
.reporting-workspace .page-heading h1 { font-size: clamp(24px, 1.9vw, 29px); margin: 5px 0 7px; }
.reporting-workspace .page-heading p:not(.eyebrow) { font-size: 13px; line-height: 1.5; max-width: none; }
.reporting-workspace .card { min-width: 0; background: var(--surface-elevated); border: 1px solid var(--report-line); border-radius: 9px; box-shadow: 0 1px 4px rgba(21, 49, 84, .045); }
.reporting-workspace .content-area { margin: 0; min-width: 0; }
.reporting-workspace .button { min-height: 38px; padding: 8px 16px; font-size: 12px; border-radius: 6px; gap: 9px; white-space: nowrap; }
.reporting-workspace .button.secondary { color: var(--report-blue); border-color: color-mix(in srgb, var(--report-blue) 50%, var(--surface-elevated)); background: var(--surface-elevated); }
.reporting-workspace .button.primary { background: var(--report-blue); border-color: var(--report-blue); color: #fff; }
.reporting-workspace .button:hover { filter: brightness(.97); }
.reporting-workspace :is(a, button, select, summary):focus-visible { outline: 2px solid var(--report-blue); outline-offset: 3px; }
.reporting-workspace .reports-module-tabs { display: inline-flex; justify-self: start; align-items: stretch; width: fit-content; padding: 0; gap: 0; border: 1px solid var(--report-line); border-radius: 6px; background: var(--surface-elevated); overflow: hidden; }
.reporting-workspace .reports-module-tabs a { display: flex; align-items: center; justify-content: center; min-width: 106px; min-height: 39px; padding: 8px 22px; border-radius: 0; border-bottom: 2px solid transparent; color: var(--text-secondary); font-size: 13px; font-weight: 700; text-decoration: none; }
.reporting-workspace .reports-module-tabs a + a { border-left: 1px solid var(--report-line); }
.reporting-workspace .reports-module-tabs a.is-active { color: var(--report-blue); background: var(--surface-elevated); border-bottom-color: var(--report-blue); box-shadow: none; }
.reporting-workspace .reports-module-tabs a:hover { background: var(--surface-hover); }
.reporting-heading-actions { display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 10px; }
.reporting-workspace .report-period-field { display: grid; gap: 8px; min-width: 0; margin: 0; color: var(--heading); font-size: 12px; font-weight: 700; }
.reporting-workspace .report-period-control { position: relative; display: block; min-width: 0; }
.reporting-workspace .report-period-control > .ui-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 1; }
.reporting-workspace .report-period-control select { padding-left: 38px; }
.reporting-workspace select { width: 100%; min-width: 0; height: 42px; margin: 0; padding: 9px 34px 9px 13px; color: var(--heading); border: 1px solid var(--report-line); border-radius: 5px; background-color: var(--surface-elevated); font-size: 12px; font-weight: 400; }
.reporting-workspace .report-period-context { display: flex; align-items: center; gap: 12px; min-width: 0; font-size: 12px; color: var(--heading); }
.reporting-workspace .report-period-context > .ui-icon { flex-shrink: 0; }
.reporting-workspace .report-period-context small { display: block; margin-top: 3px; font-size: 10px; line-height: 1.4; }
.reporting-workspace .report-period-context strong { font-weight: 650; }
.reporting-icon { --icon-color: var(--report-blue); flex-shrink: 0; display: grid; place-items: center; width: 42px; height: 42px; border-radius: 50%; color: var(--icon-color); border: 1px solid color-mix(in srgb, var(--icon-color) 20%, transparent); background: color-mix(in srgb, var(--icon-color) 8%, var(--surface-elevated)); }
.reporting-icon.tone-green { --icon-color: #008647; }
.reporting-icon.tone-purple { --icon-color: #8425ed; }
.reporting-icon.tone-cyan { --icon-color: #0096df; }
.reporting-icon.tone-orange { --icon-color: #f27a00; }
.reporting-icon.tone-red { --icon-color: #ef3b3b; }
.reporting-icon.tone-amber { --icon-color: #ba7a06; }
[data-theme="dark"] .reporting-workspace { --report-blue: #75aaff; --report-green: #6bd5a1; }
[data-theme="dark"] .reporting-workspace .button.primary { color: #071e3d; }
@media (max-width: 650px) {
    .reporting-workspace .page-heading { align-items: flex-start; gap: 14px; }
    .reporting-heading-actions { justify-content: flex-start; }
    .reporting-workspace .reports-module-tabs { width: 100%; }
    .reporting-workspace .reports-module-tabs a { flex: 1; }
}
@media print {
    .reporting-workspace .reports-module-tabs, .reporting-heading-actions, .reporting-workspace .report-generator-card, .reporting-workspace .report-output-actions, .reporting-workspace .analytics-period-toolbar { display: none !important; }
    .reporting-workspace .card { box-shadow: none; }
}
</style>

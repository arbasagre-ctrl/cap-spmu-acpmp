<style>
.my-borrowings { --borrowings-blue: #0f62d6; --borrowings-count: var(--navy-900); width: 100%; min-width: 0; font-size: 13px; }
.my-borrowings [hidden] { display: none !important; }

.my-borrowings-card { min-width: 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); overflow: hidden; }

/* Tabs */
.borrowings-tabs { display: flex; align-items: stretch; flex-wrap: wrap; gap: 6px; padding: 0 22px; border-bottom: 1px solid var(--border); }
.borrowings-tab { display: inline-flex; align-items: center; gap: 10px; padding: 18px 12px 15px; border: 0; border-bottom: 3px solid transparent; background: transparent; color: var(--text-muted); font: inherit; font-size: 14.5px; font-weight: 650; line-height: 1; cursor: pointer; transition: color var(--motion) ease, border-color var(--motion) ease; }
.borrowings-tab:hover { color: var(--text-secondary); }
.borrowings-tab:focus-visible { outline: none; box-shadow: var(--focus-ring); border-radius: 6px 6px 0 0; }
.borrowings-tab.is-active { color: var(--borrowings-blue); border-bottom-color: var(--borrowings-blue); font-weight: 750; }
.borrowings-tab-count { display: inline-grid; place-items: center; min-width: 25px; height: 25px; padding: 0 8px; border-radius: 999px; background: var(--surface-muted); color: var(--text-muted); font-size: 12px; font-weight: 700; }
.borrowings-tab.is-active .borrowings-tab-count { background: var(--borrowings-count); color: #fff; }

/* Panels */
.borrowings-panel { padding: 20px; }
.borrowings-list { display: grid; gap: 10px; }

/* Empty state */
.borrowings-empty { display: flex; min-height: clamp(320px, 44vh, 440px); align-items: center; justify-content: center; padding: 44px 20px 48px; text-align: center; }
.borrowings-empty-content { display: flex; flex-direction: column; align-items: center; max-width: 500px; }
.borrowings-empty-illustration { display: block; width: 100%; max-width: 224px; height: auto; margin-bottom: 20px; }

.borrowings-empty-backdrop { fill: #e8f0fa; }
.borrowings-empty-box { stroke: #2f74d0; fill: none; }
.borrowings-empty-clipboard { stroke: #1f6bd4; fill: #ffffff; }
.borrowings-empty-clip { fill: #d5e5f9; }
.borrowings-empty-checks { stroke: #4b8adc; fill: #f2f7fd; }
.borrowings-empty-sparkles { fill: #bed7f2; }

.my-borrowings .borrowings-empty h2 { margin: 0 0 12px; color: var(--heading); font-size: 20px; font-weight: 800; line-height: 1.35; }
.borrowings-empty p { margin: 0 0 26px; max-width: 42ch; color: var(--text-secondary); font-size: 14px; line-height: 1.6; }
.my-borrowings .button.borrowings-empty-action { gap: 10px; min-height: 46px; padding: 12px 22px; border: 1px solid var(--borrowings-blue); border-radius: 8px; background: var(--surface-elevated); color: var(--borrowings-blue); font-size: 14px; font-weight: 750; }
.my-borrowings .button.borrowings-empty-action:hover, .my-borrowings .button.borrowings-empty-action:focus-visible { color: #fff; background: var(--borrowings-blue); border-color: var(--borrowings-blue); }
.borrowings-empty-action .ui-icon { flex-shrink: 0; }

html[data-theme="dark"] .my-borrowings { --borrowings-blue: #72b7f4; --borrowings-count: #1c4a7d; }
html[data-theme="dark"] .borrowings-empty-backdrop { fill: #16263a; }
html[data-theme="dark"] .borrowings-empty-box { stroke: #4d87c9; }
html[data-theme="dark"] .borrowings-empty-clipboard { stroke: #4d87c9; fill: #17273a; }
html[data-theme="dark"] .borrowings-empty-clip { fill: #22405e; }
html[data-theme="dark"] .borrowings-empty-checks { stroke: #4d87c9; fill: #1b3049; }
html[data-theme="dark"] .borrowings-empty-sparkles { fill: #2f4a68; }
html[data-theme="dark"] .my-borrowings .button.borrowings-empty-action:hover,
html[data-theme="dark"] .my-borrowings .button.borrowings-empty-action:focus-visible { color: var(--navy-950); }

@media (max-width: 620px) {
    .borrowings-tabs { padding: 0 12px; }
    .borrowings-tab { padding: 14px 8px 11px; font-size: 13px; }
    .borrowings-tab-count { min-width: 22px; height: 22px; font-size: 11px; }
    .borrowings-panel { padding: 14px; }
    .borrowings-empty { min-height: 290px; padding: 30px 12px; }
    .borrowings-empty-illustration { max-width: 180px; }
    .my-borrowings .borrowings-empty h2 { font-size: 18px; }
    .borrowings-empty p br { display: none; }
}
@media (prefers-reduced-motion: reduce) {
    .borrowings-tab { transition: none; }
}
</style>

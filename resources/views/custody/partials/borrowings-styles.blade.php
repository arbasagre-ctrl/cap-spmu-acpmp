<style>
.my-borrowings { --borrowings-blue: #0f62d6; width: 100%; min-width: 0; font-size: 13px; }
.my-borrowings [hidden] { display: none !important; }

/* Use the same Search + Status + Sort pattern as the other transaction lists. */
.borrowings-toolbar {
    display: grid;
    grid-template-columns: minmax(280px, 1fr) minmax(190px, 230px) minmax(150px, 190px);
    gap: 12px;
    align-items: end;
    margin-bottom: 16px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
}
.borrowings-toolbar label { display: grid; gap: 6px; margin: 0; color: var(--text-muted); font-size: 12px; font-weight: 800; }
.borrowings-toolbar input,
.borrowings-toolbar select { width: 100%; min-height: 42px; }
.borrowings-toolbar .search-input-shell input { padding-left: 38px; }

.borrowings-results-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 2px 10px; color: var(--text-secondary); }
.borrowings-results-head strong { color: var(--heading); font-size: 13px; }
.borrowings-list { display: grid; gap: 10px; }

.my-borrowings-card { min-width: 0; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); overflow: hidden; }
.borrowings-filter-empty { margin-top: 10px; }

/* Empty state */
.borrowings-empty { display: flex; min-height: clamp(300px, 40vh, 400px); align-items: center; justify-content: center; padding: 40px 20px 44px; text-align: center; }
.borrowings-empty-content { display: flex; flex-direction: column; align-items: center; max-width: 500px; }
.borrowings-empty-illustration { display: block; width: 100%; max-width: 205px; height: auto; margin-bottom: 18px; }
.borrowings-empty-backdrop { fill: #e8f0fa; }
.borrowings-empty-box { stroke: #2f74d0; fill: none; }
.borrowings-empty-clipboard { stroke: #1f6bd4; fill: #ffffff; }
.borrowings-empty-clip { fill: #d5e5f9; }
.borrowings-empty-checks { stroke: #4b8adc; fill: #f2f7fd; }
.borrowings-empty-sparkles { fill: #bed7f2; }
.my-borrowings .borrowings-empty h2 { margin: 0 0 10px; color: var(--heading); font-size: 19px; font-weight: 800; line-height: 1.35; }
.borrowings-empty p { margin: 0 0 22px; max-width: 42ch; color: var(--text-secondary); font-size: 13px; line-height: 1.55; }
.my-borrowings .button.borrowings-empty-action { gap: 10px; min-height: 44px; padding: 11px 20px; border: 1px solid var(--borrowings-blue); border-radius: 8px; background: var(--surface-elevated); color: var(--borrowings-blue); font-size: 13px; font-weight: 750; }
.my-borrowings .button.borrowings-empty-action:hover,
.my-borrowings .button.borrowings-empty-action:focus-visible { color: #fff; background: var(--borrowings-blue); border-color: var(--borrowings-blue); }
.borrowings-empty-action .ui-icon { flex-shrink: 0; }

html[data-theme="dark"] .my-borrowings { --borrowings-blue: #72b7f4; }
html[data-theme="dark"] .borrowings-empty-backdrop { fill: #16263a; }
html[data-theme="dark"] .borrowings-empty-box { stroke: #4d87c9; }
html[data-theme="dark"] .borrowings-empty-clipboard { stroke: #4d87c9; fill: #17273a; }
html[data-theme="dark"] .borrowings-empty-clip { fill: #22405e; }
html[data-theme="dark"] .borrowings-empty-checks { stroke: #4d87c9; fill: #1b3049; }
html[data-theme="dark"] .borrowings-empty-sparkles { fill: #2f4a68; }
html[data-theme="dark"] .my-borrowings .button.borrowings-empty-action:hover,
html[data-theme="dark"] .my-borrowings .button.borrowings-empty-action:focus-visible { color: var(--navy-950); }

@media (max-width: 760px) {
    .borrowings-toolbar { grid-template-columns: 1fr; }
}
@media (max-width: 620px) {
    .borrowings-toolbar { padding: 12px; }
    .borrowings-empty { min-height: 280px; padding: 28px 12px; }
    .borrowings-empty-illustration { max-width: 175px; }
    .my-borrowings .borrowings-empty h2 { font-size: 18px; }
    .borrowings-empty p br { display: none; }
}
</style>

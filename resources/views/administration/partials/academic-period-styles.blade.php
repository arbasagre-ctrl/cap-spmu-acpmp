<style>
.academic-period-config { --period-blue: #0f62d6; width: 100%; min-width: 0; font-size: 13px; }
.academic-period-config [hidden] { display: none !important; }

/* Blue section eyebrows throughout this workspace */
.operational-config-heading .eyebrow,
.academic-period-config .eyebrow { color: var(--period-blue); font-size: 11px; font-weight: 800; letter-spacing: .08em; }

/* Back to Operational Configuration */
.operational-config-heading .button.operational-config-back { gap: 9px; min-height: 42px; padding: 11px 18px; border: 1px solid var(--period-blue); border-radius: 8px; background: var(--surface-elevated); color: var(--period-blue); font-size: 13px; font-weight: 700; white-space: nowrap; }
.operational-config-heading .button.operational-config-back:hover,
.operational-config-heading .button.operational-config-back:focus-visible { color: #fff; background: var(--period-blue); border-color: var(--period-blue); }
.operational-config-back .ui-icon { flex-shrink: 0; transform: rotate(180deg); }

/* Current academic period */
.academic-period-config .current-period-card { display: flex; align-items: center; gap: 22px; padding: 24px 26px; border: 1px solid var(--info-border); border-radius: 10px; background: var(--info-bg); box-shadow: none; }
.current-period-icon { display: grid; place-items: center; width: 76px; height: 76px; flex-shrink: 0; border-radius: 50%; background: var(--surface-elevated); color: var(--period-blue); }
.current-period-main { min-width: 0; display: grid; gap: 4px; }
.academic-period-config .current-period-main .eyebrow { margin: 0 0 4px; }
.current-period-value { color: var(--heading); font-size: 21px; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
.current-period-dates { color: var(--text-secondary); font-size: 13.5px; }
.academic-period-config .current-period-empty { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.55; }

/* Shared card shell */
.academic-period-config .card { padding: 24px 26px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.academic-period-config .card-header { align-items: flex-start; margin: 0 0 18px; padding: 0 0 18px; border-bottom: 1px solid var(--border); }
.academic-period-config .card-header h2 { margin: 0 0 6px; color: var(--heading); font-size: 19px; font-weight: 800; line-height: 1.3; }
.academic-period-config .card-header .meta { margin: 0; color: var(--text-muted); font-size: 13px; line-height: 1.55; }

/* Add academic period */
.academic-period-form { display: grid; gap: 20px; }
.academic-period-form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px 26px; }
.academic-period-form-grid label { min-width: 0; margin: 0; display: grid; gap: 8px; color: var(--heading); font-size: 13px; font-weight: 700; }
.academic-period-form-grid input, .academic-period-form-grid select { width: 100%; min-height: 46px; border-radius: 8px; font-size: 13.5px; font-weight: 400; }
.academic-period-form-actions { display: flex; justify-content: flex-end; }
.academic-period-config .button.academic-period-save { gap: 9px; min-height: 44px; padding: 12px 22px; border-color: var(--period-blue); border-radius: 8px; background: var(--period-blue); color: #fff; font-size: 13.5px; font-weight: 700; }
.academic-period-config .button.academic-period-save:hover,
.academic-period-config .button.academic-period-save:focus-visible { border-color: #0a4fb0; background: #0a4fb0; color: #fff; }
.academic-period-save .ui-icon { flex-shrink: 0; }

/* Configured periods toolbar */
.periods-card-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 16px 24px; }
.periods-toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
.academic-period-config .periods-search { position: relative; margin: 0; }
.academic-period-config .periods-search input { width: 100%; min-width: 250px; min-height: 44px; padding-left: 40px; border-radius: 8px; font-size: 13px; font-weight: 400; }
.periods-search .ui-icon { position: absolute; top: 50%; left: 14px; color: var(--text-muted); transform: translateY(-50%); pointer-events: none; }
.academic-period-config .periods-sort { margin: 0; }
.academic-period-config .periods-sort select { min-width: 175px; min-height: 44px; border-radius: 8px; font-size: 13px; font-weight: 400; }

/* Periods table */
.academic-period-config .periods-table { border: 1px solid var(--border); border-radius: 9px; }
.academic-period-config .periods-table table { min-width: 760px; }
.academic-period-config .periods-table th { padding: 14px 18px; border-bottom: 1px solid var(--border); background: var(--table-heading-bg); color: var(--text-secondary); font-size: 10.5px; font-weight: 750; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; }
.academic-period-config .periods-table td { padding: 16px 18px; border-bottom: 1px solid var(--row-border); font-size: 13px; vertical-align: middle; }
.periods-table tbody tr:last-child td { border-bottom: 0; }
.period-status-copy { display: block; margin-top: 4px; color: var(--text-muted); font-size: 11.5px; }
.period-actions { display: flex; align-items: center; gap: 10px; }
.period-current { color: var(--success); font-size: 12.5px; font-weight: 700; }
.period-no-action { color: var(--text-muted); font-size: 12.5px; }

/* Empty state inside the table */
.periods-empty-cell { padding: 46px 20px !important; text-align: center; }
.periods-empty { display: flex; flex-direction: column; align-items: center; }
.periods-empty-illustration { display: block; width: 100%; max-width: 170px; height: auto; margin-bottom: 16px; }
.periods-empty strong { display: block; margin-bottom: 6px; color: var(--heading); font-size: 15px; font-weight: 750; }
.periods-empty span { color: var(--text-muted); font-size: 13px; }

html[data-theme="dark"] .academic-period-config { --period-blue: #72b7f4; }
html[data-theme="dark"] .academic-period-config .button.academic-period-save,
html[data-theme="dark"] .academic-period-config .button.academic-period-save:hover,
html[data-theme="dark"] .operational-config-heading .button.operational-config-back:hover { color: var(--navy-950); }

@media (max-width: 820px) {
    .academic-period-config .current-period-card { align-items: flex-start; gap: 16px; padding: 18px; }
    .current-period-icon { width: 60px; height: 60px; }
    .academic-period-config .card { padding: 18px 16px; }
    .academic-period-form-grid { grid-template-columns: minmax(0, 1fr); }
    .academic-period-form-actions { justify-content: stretch; }
    .academic-period-config .button.academic-period-save { flex: 1 1 auto; justify-content: center; }
    .periods-toolbar { width: 100%; }
    .academic-period-config .periods-search { flex: 1 1 200px; }
    .academic-period-config .periods-search input { min-width: 0; }
}
</style>

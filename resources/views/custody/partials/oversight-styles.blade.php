<style>
.custody-oversight { --oversight-blue: #0865df; width: 100%; min-width: 0; display: grid; gap: 16px; }
.custody-oversight [hidden] { display: none !important; }

/* Status tab cards */
.custody-oversight-tabs { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 10px; }
.custody-oversight-tab { display: flex; align-items: center; gap: 10px; min-width: 0; min-height: 56px; padding: 11px 13px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); color: var(--text-secondary); font: inherit; font-size: 12px; text-align: left; cursor: pointer; transition: border-color var(--motion) ease, background-color var(--motion) ease, box-shadow var(--motion) ease; }
.custody-oversight-tab:hover { border-color: var(--border-strong); background: var(--surface-hover); }
.custody-oversight-tab:focus-visible { outline: none; box-shadow: var(--focus-ring); }
.custody-oversight-tab.is-active { border-color: var(--oversight-blue); background: var(--info-bg); color: var(--heading); }
.custody-oversight-tab-icon { display: grid; place-items: center; width: 24px; height: 24px; flex-shrink: 0; color: var(--text-muted); }
.custody-oversight-tab-label { flex: 1 1 auto; min-width: 0; font-weight: 700; line-height: 1.35; overflow-wrap: anywhere; }
.custody-oversight-tab-count { flex-shrink: 0; color: var(--heading); font-size: 14px; font-weight: 800; font-variant-numeric: tabular-nums; }
.custody-oversight-tab.is-active .custody-oversight-tab-count { color: var(--oversight-blue); }
.custody-oversight-tab[data-custody-tab="all"] .custody-oversight-tab-icon { color: var(--text-secondary); }
.custody-oversight-tab[data-custody-tab="active"] .custody-oversight-tab-icon { color: var(--success); }
.custody-oversight-tab[data-custody-tab="attention"] .custody-oversight-tab-icon { color: #c1610a; }
.custody-oversight-tab[data-custody-tab="release"] .custody-oversight-tab-icon,
.custody-oversight-tab[data-custody-tab="custody"] .custody-oversight-tab-icon,
.custody-oversight-tab[data-custody-tab="return"] .custody-oversight-tab-icon,
.custody-oversight-tab[data-custody-tab="completed"] .custody-oversight-tab-icon { color: var(--oversight-blue); }

/* Filter bar */
.custody-oversight-filters { display: grid; grid-template-columns: minmax(240px, 2.1fr) minmax(140px, .8fr) minmax(140px, .8fr) minmax(170px, 1fr) auto; align-items: end; gap: 12px; padding: 16px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.custody-oversight-filters label { min-width: 0; margin: 0; display: grid; gap: 7px; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.custody-oversight-filters input, .custody-oversight-filters select { width: 100%; min-height: 40px; border-radius: 7px; font-size: 12px; }
.custody-oversight-filters .search-input-shell input { padding-left: 36px; }
.custody-oversight .button.custody-oversight-clear { min-height: 40px; padding: 10px 20px; border-radius: 7px; font-size: 12px; }
.custody-oversight-date-error { grid-column: 1 / -1; margin: 0; padding: 9px 12px; border: 1px solid var(--danger-border); border-radius: 7px; background: var(--danger-bg); color: var(--danger); font-size: 11px; font-weight: 700; line-height: 1.5; }
.custody-oversight-filters input.is-invalid { border-color: var(--danger); box-shadow: 0 0 0 2px var(--danger-bg); }

/* Result summary */
.custody-oversight-summary { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 8px 20px; padding: 0 3px; color: var(--text-muted); font-size: 12px; }
.custody-oversight-summary strong { color: var(--heading); font-weight: 750; }

/* Transaction rows */
.custody-oversight-list { display: grid; gap: 10px; }
.custody-oversight-row { display: grid; grid-template-columns: minmax(210px, 1fr) minmax(0, 2.2fr); align-items: center; gap: 14px 18px; padding: 14px 16px; border: 1px solid var(--border); border-left: 3px solid var(--border-strong); border-radius: 9px; background: var(--surface-elevated); color: inherit; text-decoration: none; }
.custody-oversight-row:hover, .custody-oversight-row:focus-visible { box-shadow: var(--shadow-sm); border-color: var(--border-strong); background: var(--row-hover); }
.custody-oversight-row[data-custody-group="attention"] { border-left-color: var(--danger); }
.custody-oversight-row[data-custody-group="release"] { border-left-color: #c1610a; }
.custody-oversight-row[data-custody-group="custody"] { border-left-color: var(--oversight-blue); }
.custody-oversight-row[data-custody-group="return"] { border-left-color: #0b6f8c; }
.custody-oversight-row[data-custody-group="completed"] { border-left-color: var(--success); }

.custody-oversight-borrower { display: flex; align-items: flex-start; gap: 11px; min-width: 0; }
.custody-oversight-avatar { display: grid; place-items: center; width: 34px; height: 34px; flex-shrink: 0; border-radius: 50%; color: #fff; font-size: 11px; font-weight: 800; letter-spacing: .02em; }
.custody-oversight-avatar[data-avatar-tone="1"] { background: #1f63c8; }
.custody-oversight-avatar[data-avatar-tone="2"] { background: #17825c; }
.custody-oversight-avatar[data-avatar-tone="3"] { background: #b4590c; }
.custody-oversight-avatar[data-avatar-tone="4"] { background: #7a3fbe; }
.custody-oversight-avatar[data-avatar-tone="5"] { background: #0b6f8c; }
.custody-oversight-avatar[data-avatar-tone="6"] { background: #b03a5b; }
.custody-oversight-identity { min-width: 0; display: grid; gap: 2px; }
.custody-oversight-name { display: flex; align-items: center; gap: 6px; min-width: 0; color: var(--heading); font-size: 13px; font-weight: 750; line-height: 1.4; }
.custody-oversight-name > span { overflow-wrap: anywhere; }
.custody-oversight-unit { display: inline-grid; place-items: center; flex-shrink: 0; color: var(--text-soft); cursor: help; }
.custody-oversight-request { color: var(--text-secondary); font-size: 12px; }
.custody-oversight-schedule { color: var(--text-muted); font-size: 11px; line-height: 1.5; }

.custody-oversight-facts { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); align-items: start; gap: 12px 16px; min-width: 0; }
.custody-oversight-fact { min-width: 0; display: grid; gap: 4px; }
.custody-oversight-fact > small { color: var(--text-muted); font-size: 10px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; }
.custody-oversight-fact > strong { color: var(--heading); font-size: 12px; font-weight: 650; line-height: 1.45; overflow-wrap: anywhere; }
.custody-oversight-fact .status-badge { justify-self: start; }
.custody-oversight-row[data-custody-group="release"] .custody-oversight-fact .status-badge { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
.custody-oversight-row[data-custody-group="return"] .custody-oversight-fact .status-badge { color: var(--info); background: var(--info-bg); border-color: var(--info-border); }

/* Footer / pagination */
.custody-oversight-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 18px; padding: 4px 3px 0; }
.custody-oversight-page-size { display: flex; align-items: center; gap: 8px; margin: 0; color: var(--text-secondary); font-size: 12px; font-weight: 650; }
.custody-oversight-page-size select { min-height: 34px; width: auto; min-width: 68px; padding: 6px 34px 6px 10px; border-radius: 6px; font-size: 12px; }
.custody-oversight-pagination { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 7px; margin-left: auto; }
.custody-oversight-page { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 5px 9px; border: 1px solid var(--border); border-radius: 5px; background: var(--surface-elevated); color: var(--text-secondary); font: inherit; font-size: 11px; line-height: 1; cursor: pointer; }
.custody-oversight-page:hover:not(:disabled):not(.is-active) { color: var(--oversight-blue); border-color: var(--oversight-blue); }
.custody-oversight-page.is-active { color: #fff; background: var(--oversight-blue); border-color: var(--oversight-blue); font-weight: 750; }
.custody-oversight-page:disabled { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.custody-oversight-page-previous { transform: rotate(180deg); }
.custody-oversight-page-ellipsis { padding: 0 3px; color: var(--text-muted); font-size: 11px; }

/* Empty and no-results states */
.custody-oversight-empty { display: flex; min-height: clamp(300px, 40vh, 420px); align-items: center; justify-content: center; padding: 40px 22px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); text-align: center; }
.custody-oversight-empty-content { display: flex; flex-direction: column; align-items: center; max-width: 460px; }
.custody-oversight-empty-icon { display: grid; place-items: center; width: 86px; height: 86px; margin-bottom: 22px; border: 1px solid var(--border); border-radius: 50%; background: var(--surface-subtle); color: var(--oversight-blue); }
.custody-oversight-empty h2 { margin: 0 0 10px; font-size: 17px; font-weight: 750; line-height: 1.5; }
.custody-oversight-empty p { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.7; }

html[data-theme="dark"] .custody-oversight { --oversight-blue: #72b7f4; }
html[data-theme="dark"] .custody-oversight-tab[data-custody-tab="attention"] .custody-oversight-tab-icon { color: var(--warning); }
html[data-theme="dark"] .custody-oversight-row[data-custody-group="release"] { border-left-color: var(--warning); }
html[data-theme="dark"] .custody-oversight-page.is-active { color: var(--navy-950); }

@media (max-width: 1180px) {
    .custody-oversight-tabs { grid-template-columns: repeat(4, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
    .custody-oversight-filters { grid-template-columns: 1fr 1fr; }
    .custody-oversight-search { grid-column: 1 / -1; }
    .custody-oversight-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 620px) {
    .custody-oversight-tabs { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .custody-oversight-filters { grid-template-columns: 1fr; }
    .custody-oversight-row { grid-template-columns: minmax(0, 1fr); }
    .custody-oversight .button.custody-oversight-clear { width: 100%; justify-content: center; }
    .custody-oversight-footer { align-items: flex-start; }
    .custody-oversight-pagination { margin-left: 0; }
}
@media (prefers-reduced-motion: reduce) {
    .custody-oversight-tab { transition: none; }
}
</style>

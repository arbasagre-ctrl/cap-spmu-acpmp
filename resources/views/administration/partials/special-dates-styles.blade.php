<style>
.special-dates-config { min-width: 0; }
.special-dates-config [hidden] { display: none !important; }
.special-dates-config .special-dates-card { padding: 22px 24px 24px; }

/* Card header: schedule-exception identity with a calendar tile. */
.special-dates-config .special-dates-header { display: flex; align-items: flex-start; gap: 18px; margin: 0 0 20px; padding-bottom: 20px; border-bottom: 1px solid var(--border); }
.special-dates-config .special-dates-header > div { min-width: 0; }
.special-dates-config .special-dates-header-icon { display: grid; flex-shrink: 0; place-items: center; width: 52px; height: 52px; border-radius: 13px; background: var(--blue-50); color: var(--interactive); }
.special-dates-config .special-dates-header h2 { margin: 0 0 7px; font-size: 18px; }
.special-dates-config .special-dates-header .eyebrow { margin-bottom: 6px; }
.special-dates-config .special-dates-header .meta { max-width: 640px; margin: 0; font-size: 12.5px; line-height: 1.6; }

/* Exception form: one field row, one capability row, then reason and save. */
.special-dates-config .special-date-form { display: block; }
.special-dates-config .special-date-fields { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 18px; }
.special-dates-config .special-date-form label { margin: 0; font-size: 12.5px; }
.special-dates-config .special-date-form input, .special-dates-config .special-date-form select { min-height: 44px; border-color: var(--border); border-radius: 8px; }
.special-dates-config .special-date-capabilities { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 16px; }
.special-dates-config .special-date-check { display: flex; align-items: center; gap: 10px; min-height: 44px; margin: 0; padding: 10px 16px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); color: var(--text-secondary); cursor: pointer; font-size: 12.5px; font-weight: 700; transition: border-color var(--motion) ease, background-color var(--motion) ease; }
.special-dates-config .special-date-check:hover { border-color: var(--border-strong); background: var(--surface-hover); }
.special-dates-config .special-date-check:has(input[type="checkbox"]:checked) { border-color: var(--interactive); background: var(--blue-50); color: var(--heading); }
.special-dates-config .special-date-check:has(input[type="checkbox"]:focus-visible) { border-color: var(--interactive); box-shadow: var(--focus-ring); }
.special-dates-config .special-date-check input[type="checkbox"] { width: 17px; height: 17px; flex-shrink: 0; margin: 0; }
.special-dates-config .special-date-reason { margin-top: 18px; }
.special-dates-config .special-date-submit { gap: 9px; margin-top: 18px; min-height: 44px; padding: 10px 20px; }
.special-dates-config .special-date-submit .ui-icon { flex-shrink: 0; }

/* Scheduled exceptions register. */
.special-dates-config .special-dates-records { margin-top: 24px; overflow: hidden; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface-elevated); }
.special-dates-config .special-dates-records-header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; padding: 15px 18px; }
.special-dates-config .special-dates-records-header h3 { display: flex; align-items: center; gap: 10px; margin: 0; font-size: 14.5px; font-weight: 750; }
.special-dates-config .special-dates-count { display: inline-flex; min-width: 26px; height: 22px; align-items: center; justify-content: center; padding: 0 8px; border-radius: 999px; background: var(--blue-50); color: var(--interactive); font-size: 11px; font-weight: 800; font-variant-numeric: tabular-nums; }
.special-dates-config .special-dates-search { display: block; width: min(100%, 330px); margin: 0; }
.special-dates-config .special-dates-search-shell > input[type="search"] { min-height: 40px; padding: 9px 40px 9px 13px; border-color: var(--border); border-radius: 8px; font-size: 12px; }
.special-dates-config .special-dates-search-shell .search-input-icon { right: 12px; left: auto; }

/* Register table. */
.special-dates-config .special-dates-table-wrap { border: 0; border-top: 1px solid var(--border); border-radius: 0; box-shadow: none; }
.special-dates-config .special-dates-table-wrap table { min-width: 900px; }
.special-dates-config .special-dates-table-wrap tr[hidden] { display: none; }
.special-dates-config .special-dates-sort { display: inline-flex; min-height: 0; align-items: center; gap: 6px; padding: 0; border: 0; border-radius: 4px; background: none; color: inherit; cursor: pointer; font: inherit; letter-spacing: inherit; text-transform: inherit; }
.special-dates-config .special-dates-sort:hover { background: none; box-shadow: none; color: var(--heading); }
.special-dates-config .special-dates-sort:focus-visible { outline: 0; box-shadow: var(--focus-ring); }
.special-dates-config .special-dates-sort .ui-icon { flex-shrink: 0; opacity: .5; transition: opacity var(--motion) ease; }
.special-dates-config .special-dates-sort.is-sorted { color: var(--interactive); }
.special-dates-config .special-dates-sort.is-sorted .ui-icon { opacity: 1; }
.special-dates-config .special-dates-table-wrap td { font-size: 12.5px; }
.special-dates-config .special-dates-table-wrap td small { color: var(--text-muted); }

/* Empty and no-result states. */
.special-dates-config td.special-dates-empty { height: auto; padding: 46px 18px; }
.special-dates-config .special-dates-empty-content { display: grid; justify-items: center; gap: 0; text-align: center; }
.special-dates-config .special-dates-empty-art { margin-bottom: 16px; }
.special-dates-config .special-dates-empty h4 { margin: 0 0 7px; color: var(--heading); font-size: 15px; font-weight: 750; }
.special-dates-config .special-dates-empty p { margin: 0; color: var(--text-muted); font-size: 12.5px; }

@media (max-width: 1180px) {
    .special-dates-config .special-date-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 720px) {
    .special-dates-config .special-dates-card { padding: 18px; }
    .special-dates-config .special-dates-header { gap: 14px; }
    .special-dates-config .special-date-fields { grid-template-columns: minmax(0, 1fr); }
    .special-dates-config .special-dates-search { width: 100%; }
    .special-dates-config .special-date-submit { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .special-dates-config .special-date-check, .special-dates-config .special-dates-sort .ui-icon { transition: none; }
}
</style>

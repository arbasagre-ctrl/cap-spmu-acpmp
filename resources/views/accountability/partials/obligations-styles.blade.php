<style>
/*
|--------------------------------------------------------------------------
| Borrower - My Obligations
|--------------------------------------------------------------------------
|
| Sections: 1. Summary cards  2. Toolbar  3. Table  4. Detail row
|           5. Footer  6. Empty state  7. Responsive
|
*/

.ob-workspace { display: grid; gap: 16px; }

/* 1. Summary cards -------------------------------------------------------- */

.ob-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.ob-summary-card {
    display: flex;
    align-items: center;
    gap: 15px;
    width: 100%;
    min-width: 0;
    padding: 18px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-top: 3px solid var(--ob-accent);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    text-align: left;
    cursor: pointer;
    transition: border-color var(--motion) ease, box-shadow var(--motion) ease;
}

.ob-summary-card:hover { box-shadow: var(--shadow); }

.ob-summary-card:focus-visible { outline: 0; box-shadow: var(--focus-ring); }

.ob-summary-card.is-selected {
    border-color: var(--ob-accent);
    border-top-color: var(--ob-accent);
    box-shadow: 0 0 0 1px var(--ob-accent) inset, var(--shadow-sm);
}

.ob-summary-card.is-danger  { --ob-accent: #dc3545; --ob-tint: #fdecee; --ob-ink: #c02636; }
.ob-summary-card.is-warning { --ob-accent: #e0a11b; --ob-tint: #fdf3dd; --ob-ink: #9a6a06; }
.ob-summary-card.is-info    { --ob-accent: #1769e0; --ob-tint: #e8f1fd; --ob-ink: #1157bd; }
.ob-summary-card.is-orange  { --ob-accent: #ef7a29; --ob-tint: #fdeee2; --ob-ink: #c25a12; }

html[data-theme="dark"] .ob-summary-card.is-danger  { --ob-tint: #351b1d; --ob-ink: #ff9b93; }
html[data-theme="dark"] .ob-summary-card.is-warning { --ob-tint: #332711; --ob-ink: #f3c56a; }
html[data-theme="dark"] .ob-summary-card.is-info    { --ob-tint: #14293d; --ob-ink: #86c6fb; }
html[data-theme="dark"] .ob-summary-card.is-orange  { --ob-tint: #33210f; --ob-ink: #f7ad6f; }

.ob-summary-icon {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    width: 48px;
    height: 48px;
    color: var(--ob-ink);
    background: var(--ob-tint);
    border-radius: 12px;
}

.ob-summary-copy { min-width: 0; }

.ob-summary-value {
    display: block;
    color: var(--heading);
    font-size: 27px;
    font-weight: 750;
    line-height: 1.1;
    letter-spacing: -.02em;
}

.ob-summary-label {
    display: block;
    margin-top: 2px;
    color: var(--heading);
    font-size: 13.5px;
    font-weight: 700;
    line-height: 1.3;
}

.ob-summary-note {
    display: block;
    margin-top: 3px;
    color: var(--ob-ink);
    font-size: 11.5px;
    font-weight: 650;
    line-height: 1.3;
}

.ob-summary-card.is-empty .ob-summary-note { color: var(--text-muted); font-weight: 600; }

/* 2. Toolbar -------------------------------------------------------------- */

.ob-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(190px, 240px) minmax(190px, 240px);
    gap: 18px;
    padding: 18px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
}

.ob-field {
    display: grid;
    gap: 7px;
    margin: 0;
    min-width: 0;
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 700;
}

.ob-field-shell {
    position: relative;
    display: block;
    background: var(--input-bg);
    border: 1px solid var(--border);
    border-radius: 8px;
}

.ob-field-shell:focus-within { border-color: var(--interactive); box-shadow: var(--focus-ring); }

.ob-field-shell input {
    width: 100%;
    min-height: 44px;
    min-width: 0;
    padding: 0 13px 0 42px;
    background: transparent;
    border: 0;
    box-shadow: none;
    outline: 0;
    font-size: 13px;
    font-weight: 400;
}

.ob-field-shell .search-input-icon {
    position: absolute;
    top: 50%;
    left: 14px;
    display: inline-flex;
    color: var(--text-soft);
    transform: translateY(-50%);
    pointer-events: none;
}

.ob-field select {
    width: 100%;
    min-height: 44px;
    margin: 0;
    font-size: 13px;
    font-weight: 400;
}

/* 3. Table ---------------------------------------------------------------- */

.ob-table-card {
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.ob-table-card[hidden], .ob-empty-card[hidden], .ob-footer-outside[hidden] { display: none; }

.ob-table-heading {
    padding: 16px 20px;
    color: var(--heading);
    font-size: 15px;
    font-weight: 700;
    border-bottom: 1px solid var(--border);
}

.ob-table-scroll { width: 100%; overflow-x: auto; }

.ob-table {
    width: 100%;
    min-width: 940px;
    margin: 0;
    border-collapse: collapse;
}

.ob-table th {
    padding: 11px 16px;
    color: var(--text-muted);
    background: var(--surface-subtle);
    border-bottom: 1px solid var(--border);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .06em;
    text-align: left;
    text-transform: uppercase;
    white-space: nowrap;
}

.ob-table td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--row-border);
    vertical-align: middle;
}

.ob-table tbody tr:last-child > td { border-bottom: 0; }

.ob-table tbody tr[hidden] { display: none; }

/* Type */
.ob-type { display: inline-flex; align-items: center; gap: 11px; }

.ob-type-icon {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    width: 34px;
    height: 34px;
    color: var(--ob-ink);
    background: var(--ob-tint);
    border-radius: 9px;
}

.ob-type-label {
    color: var(--ob-ink);
    font-size: 12.5px;
    font-weight: 700;
    white-space: nowrap;
}

.ob-type.is-danger  { --ob-tint: #fdecee; --ob-ink: #c02636; }
.ob-type.is-warning { --ob-tint: #fdf3dd; --ob-ink: #9a6a06; }
.ob-type.is-info    { --ob-tint: #e8f1fd; --ob-ink: #1157bd; }
.ob-type.is-orange  { --ob-tint: #fdeee2; --ob-ink: #c25a12; }

html[data-theme="dark"] .ob-type.is-danger  { --ob-tint: #351b1d; --ob-ink: #ff9b93; }
html[data-theme="dark"] .ob-type.is-warning { --ob-tint: #332711; --ob-ink: #f3c56a; }
html[data-theme="dark"] .ob-type.is-info    { --ob-tint: #14293d; --ob-ink: #86c6fb; }
html[data-theme="dark"] .ob-type.is-orange  { --ob-tint: #33210f; --ob-ink: #f7ad6f; }

/* Cell text */
.ob-primary {
    display: block;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.ob-secondary {
    display: block;
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.ob-col-description { max-width: 260px; }
.ob-col-reference { white-space: nowrap; }

/* Status */
.ob-badge {
    display: inline-flex;
    align-items: center;
    min-height: 22px;
    padding: 3px 10px;
    border: 1px solid transparent;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.ob-badge.is-danger  { color: var(--danger); background: var(--danger-bg); border-color: var(--danger-border); }
.ob-badge.is-warning { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
.ob-badge.is-info    { color: var(--info); background: var(--info-bg); border-color: var(--info-border); }
.ob-badge.is-success { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
.ob-badge.is-neutral { color: var(--neutral); background: var(--neutral-bg); border-color: var(--neutral-border); }

/* Action */
.ob-actions { display: inline-flex; align-items: center; gap: 8px; }

.ob-view {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 32px;
    padding: 5px 16px;
    color: var(--interactive);
    background: transparent;
    border: 1px solid var(--interactive);
    border-radius: 7px;
    font: inherit;
    font-size: 12.5px;
    font-weight: 700;
    cursor: pointer;
    transition: color var(--motion) ease, background-color var(--motion) ease;
}

.ob-view:hover { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }
.ob-view:focus-visible { outline: 0; box-shadow: var(--focus-ring); }

.ob-menu { position: relative; }

.ob-menu-trigger {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    padding: 0;
    color: var(--text-muted);
    background: transparent;
    border: 1px solid transparent;
    border-radius: 7px;
    cursor: pointer;
}

.ob-menu-trigger:hover,
.ob-menu-trigger[aria-expanded="true"] {
    color: var(--heading);
    background: var(--surface-muted);
    border-color: var(--border);
}

.ob-menu-trigger:focus-visible { outline: 0; box-shadow: var(--focus-ring); }

.ob-menu-panel {
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 30;
    display: grid;
    min-width: 200px;
    padding: 5px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 9px;
    box-shadow: var(--shadow);
}

.ob-menu-panel[hidden] { display: none; }

.ob-menu-panel > a,
.ob-menu-panel > button {
    display: block;
    width: 100%;
    padding: 8px 10px;
    color: var(--text-secondary);
    background: transparent;
    border: 0;
    border-radius: 6px;
    font: inherit;
    font-size: 12px;
    font-weight: 650;
    text-align: left;
    text-decoration: none;
    cursor: pointer;
}

.ob-menu-panel > a:hover,
.ob-menu-panel > button:hover { color: var(--heading); background: var(--surface-hover); }

/* 4. Detail row ----------------------------------------------------------- */

/*
 * View opens the full record beneath its own row, so the guidance that used
 * to sit in a stack of large cards is still one click away from the table.
 */
.ob-detail-row > td {
    padding: 0 16px 18px;
    background: var(--surface-subtle);
    border-bottom: 1px solid var(--row-border);
}

.ob-detail-row[hidden] { display: none; }

.ob-detail {
    display: grid;
    gap: 12px;
    padding: 16px 18px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 10px;
}

.ob-detail-facts {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px 18px;
}

.ob-detail-facts small {
    display: block;
    margin-bottom: 3px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.ob-detail-facts strong {
    display: block;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.ob-detail-action {
    padding: 12px 14px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-left: 3px solid var(--interactive);
    border-radius: 8px;
}

.ob-detail-action.is-warning { border-left-color: var(--warning); }
.ob-detail-action.is-danger { border-left-color: var(--danger); }

.ob-detail-action span {
    display: block;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.ob-detail-action strong {
    display: block;
    margin-top: 3px;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}

.ob-detail-action p {
    margin: 5px 0 0;
    color: var(--text-muted);
    font-size: 12.5px;
    line-height: 1.5;
}

.ob-detail-links { display: flex; flex-wrap: wrap; gap: 10px; }

/* 5. Footer --------------------------------------------------------------- */

.ob-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 14px 20px;
    border-top: 1px solid var(--border);
}

.ob-footer-outside {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    padding: 2px 4px;
}

.ob-footer p, .ob-footer-outside p {
    margin: 0;
    color: var(--text-secondary);
    font-size: 12.5px;
}

.ob-pagination { display: flex; align-items: center; gap: 8px; }

.ob-page {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 34px;
    height: 34px;
    padding: 4px 9px;
    color: var(--text-secondary);
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 7px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}

.ob-page.is-active { color: #fff; background: var(--primary-action); border-color: var(--primary-action); }

.ob-page[aria-disabled="true"] { color: var(--text-soft); background: var(--surface-subtle); }

.ob-page-previous .ui-icon { transform: rotate(180deg); }

/* 6. Empty state ---------------------------------------------------------- */

.ob-empty-card {
    display: grid;
    justify-items: center;
    gap: 20px;
    padding: 66px 24px 70px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    text-align: center;
}

.ob-empty-mark {
    display: grid;
    place-items: center;
    width: 112px;
    height: 112px;
    color: var(--interactive);
    background: var(--blue-50);
    border-radius: 50%;
}

.ob-empty-copy { display: grid; gap: 8px; max-width: 460px; }

.ob-empty-copy strong { color: var(--heading); font-size: 23px; font-weight: 750; letter-spacing: -.015em; }

.ob-empty-copy span { color: var(--text-muted); font-size: 13.5px; line-height: 1.55; }

/* 7. Responsive ----------------------------------------------------------- */

@media (max-width: 1180px) {
    .ob-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 900px) {
    .ob-toolbar { grid-template-columns: 1fr; }
}

@media (max-width: 620px) {
    .ob-summary { grid-template-columns: 1fr; }
    .ob-empty-card { padding: 44px 20px 48px; }
    .ob-empty-mark { width: 88px; height: 88px; }
    .ob-empty-copy strong { font-size: 20px; }
    .ob-footer, .ob-footer-outside { justify-content: center; }
}
</style>

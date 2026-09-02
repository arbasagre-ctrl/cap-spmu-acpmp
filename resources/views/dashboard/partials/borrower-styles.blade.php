<style>
/*
|--------------------------------------------------------------------------
| Borrower dashboard
|--------------------------------------------------------------------------
|
| Scoped to .is-borrower-dashboard so the SPMU, Head, and ICTU dashboards
| keep the shared two-column layout and equal-height panels.
|
*/

.is-borrower-dashboard { --borrower-dash-blue: #0f62d6; }

/* 1. Summary cards -------------------------------------------------------- */

/*
 * Compact and equal height. The count leads, the label follows, and the
 * chevron only hints that the card opens somewhere.
 */
.is-borrower-dashboard .dashboard-stat-grid .dashboard-kpi-card {
    min-height: 0;
    padding: 16px 18px;
    border-radius: 10px;
    row-gap: 8px;
}

.is-borrower-dashboard .dashboard-kpi-card .kpi-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
}

.is-borrower-dashboard .dashboard-kpi-card .kpi-value {
    font-size: 30px;
    line-height: 1;
}

.is-borrower-dashboard .dashboard-kpi-card .kpi-label {
    color: var(--text-secondary);
    font-size: 12.5px;
    font-weight: 700;
}

/* 2. Shared card shell ---------------------------------------------------- */

.borrower-dash-card {
    min-width: 0;
    margin-bottom: 18px;
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

/*
 * Content-driven height, and one radius for both sections. The specificity
 * has to clear .dashboard-balanced-grid > .card, which sets 12px.
 */
.is-borrower-dashboard .dashboard-panel-equal,
.is-borrower-dashboard .dashboard-balanced-grid > .borrower-dash-card,
.borrower-dash-card {
    min-height: 0;
    height: auto;
    border-radius: 10px;
}

.is-borrower-dashboard .borrower-dash-card > .card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px 20px;
    margin: 0;
    padding: 17px 20px;
    border-bottom: 1px solid var(--border);
    background: transparent;
}

.is-borrower-dashboard .borrower-dash-card > .card-header h2 {
    margin: 0;
    color: var(--heading);
    font-size: 16px;
    font-weight: 750;
    line-height: 1.3;
}

.is-borrower-dashboard .borrower-dash-card > .card-header .meta {
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 12.5px;
    line-height: 1.5;
}

.is-borrower-dashboard .borrower-dash-card > .card-header .dashboard-view-all {
    flex: 0 0 auto;
}

/* 3. Active requests table ------------------------------------------------ */

.borrower-active-scroll {
    width: 100%;
    min-width: 0;
    overflow-x: auto;
}

.is-borrower-dashboard .borrower-active-table {
    width: 100%;
    min-width: 720px;
    margin: 0;
    border-collapse: collapse;
}

.is-borrower-dashboard .borrower-active-table th {
    padding: 11px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--table-heading-bg);
    color: var(--text-secondary);
    font-size: 10px;
    font-weight: 750;
    letter-spacing: .06em;
    text-transform: uppercase;
    text-align: left;
    white-space: nowrap;
}

.is-borrower-dashboard .borrower-active-table td {
    padding: 12px 20px;
    border-bottom: 1px solid var(--row-border);
    color: var(--text-secondary);
    font-size: 12.5px;
    vertical-align: middle;
}

.borrower-active-table tbody tr:last-child td { border-bottom: 0; }
.borrower-active-table tbody tr:hover td { background: var(--row-hover); }

/*
 * The last two columns are sized so the schedule text and the View button
 * land on the same vertical lines as the Your-next-actions grid below:
 * 236px = 196 content + 2x20 cell padding, 128px = 88 button + 2x20.
 */
.is-borrower-dashboard .borrower-active-table th:nth-child(4),
.is-borrower-dashboard .borrower-active-table td:nth-child(4) { width: 236px; }
.is-borrower-dashboard .borrower-active-table th:nth-child(5),
.is-borrower-dashboard .borrower-active-table td:nth-child(5) { width: 128px; }

.borrower-active-id {
    color: var(--heading);
    font-weight: 700;
    white-space: nowrap;
}

.borrower-active-purpose {
    display: block;
    max-width: 30ch;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.borrower-active-schedule strong {
    display: block;
    color: var(--heading);
    font-weight: 650;
    white-space: nowrap;
}

.borrower-active-schedule small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

.is-borrower-dashboard .borrower-active-table .status-badge {
    padding: 3px 9px;
    font-size: 10.5px;
    white-space: nowrap;
}

.is-borrower-dashboard .borrower-active-action {
    width: 88px;
    min-height: 32px;
    padding: 6px 10px;
    border-color: var(--borrower-dash-blue);
    border-radius: 6px;
    background: var(--surface-elevated);
    color: var(--borrower-dash-blue);
    font-size: 11px;
    white-space: nowrap;
}

.is-borrower-dashboard .borrower-active-action:hover,
.is-borrower-dashboard .borrower-active-action:focus-visible {
    color: #fff;
    background: var(--borrower-dash-blue);
    border-color: var(--borrower-dash-blue);
}

.borrower-active-overflow {
    margin: 0;
    padding: 12px 20px;
    border-top: 1px solid var(--row-border);
    color: var(--text-muted);
    font-size: 12px;
}

/* 4. Next actions --------------------------------------------------------- */

.borrower-next-list { display: grid; }

/*
 * .queue-list article is a flex row with space-between, which pushed the icon
 * away from the details and let the button reach the card edge. These rules
 * need the extra class to outrank it.
 */
.is-borrower-dashboard .queue-list .borrower-next-row {
    display: grid;
    grid-template-columns: 44px minmax(0, 1fr) 220px 88px;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--row-border);
}

.is-borrower-dashboard .queue-list .borrower-next-row:first-child { padding-top: 14px; }

.is-borrower-dashboard .queue-list .borrower-next-row:last-child {
    padding-bottom: 14px;
    border-bottom: 0;
}

.borrower-next-icon {
    display: grid;
    place-items: center;
    justify-self: center;
    width: 40px;
    height: 40px;
    flex-shrink: 0;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--blue-50);
    color: var(--borrower-dash-blue);
}

.borrower-next-icon.tone-warning {
    border-color: var(--warning-border);
    background: var(--warning-bg);
    color: var(--warning);
}

.borrower-next-icon.tone-danger {
    border-color: var(--danger-border);
    background: var(--danger-bg);
    color: var(--danger);
}

.borrower-next-icon.tone-success {
    border-color: var(--success-border);
    background: var(--success-bg);
    color: var(--success);
}

.is-borrower-dashboard .queue-list .borrower-next-row > .borrower-next-copy {
    display: grid;
    gap: 2px;
    min-width: 0;
}

.borrower-next-copy strong {
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}

.borrower-next-copy > span {
    color: var(--text-secondary);
    font-size: 12.5px;
    overflow-wrap: anywhere;
}

.borrower-next-copy small {
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.5;
}

.borrower-next-when {
    min-width: 0;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 650;
    white-space: nowrap;
    text-align: left;
}

.is-borrower-dashboard .queue-list .borrower-next-row > .borrower-active-action {
    justify-self: center;
}

/* 5. Empty states --------------------------------------------------------- */

.borrower-dash-empty {
    display: grid;
    justify-items: center;
    gap: 5px;
    padding: 34px 20px;
    text-align: center;
}

.borrower-dash-empty > .ui-icon { margin-bottom: 5px; color: var(--text-soft); }

.borrower-dash-empty strong {
    color: var(--heading);
    font-size: 14px;
    font-weight: 750;
}

.borrower-dash-empty span {
    color: var(--text-muted);
    font-size: 12.5px;
}

html[data-theme="dark"] .is-borrower-dashboard { --borrower-dash-blue: #72b7f4; }
html[data-theme="dark"] .is-borrower-dashboard .borrower-active-action:hover,
html[data-theme="dark"] .is-borrower-dashboard .borrower-active-action:focus-visible { color: var(--navy-950); }

/* 6. Responsive ----------------------------------------------------------- */

/*
 * Below the desktop grid the date and the button drop under the details
 * column rather than squeezing four tracks into a narrow card.
 */
@media (max-width: 1000px) {
    .is-borrower-dashboard .queue-list .borrower-next-row {
        grid-template-columns: 44px minmax(0, 1fr);
        align-items: start;
        row-gap: 10px;
    }

    .is-borrower-dashboard .queue-list .borrower-next-row > .borrower-next-when,
    .is-borrower-dashboard .queue-list .borrower-next-row > .borrower-active-action {
        grid-column: 2;
        justify-self: start;
        text-align: left;
    }

    /* The empty placeholder span collapses when there is no date. */
    .is-borrower-dashboard .queue-list .borrower-next-row > span:empty { display: none; }
}

@media (max-width: 760px) {
    .is-borrower-dashboard .borrower-dash-card > .card-header { padding: 14px; }

    /* Table rows become stacked blocks so the page never scrolls sideways. */
    .borrower-active-scroll { overflow-x: visible; }
    .is-borrower-dashboard .borrower-active-table { min-width: 0; }
    .is-borrower-dashboard .borrower-active-table thead { display: none; }
    .is-borrower-dashboard .borrower-active-table tbody tr {
        display: grid;
        gap: 6px;
        padding: 14px;
        border-bottom: 1px solid var(--row-border);
    }
    .is-borrower-dashboard .borrower-active-table td {
        padding: 0;
        border-bottom: 0;
    }
    .is-borrower-dashboard .borrower-active-table td::before {
        display: block;
        margin-bottom: 2px;
        color: var(--text-muted);
        font-size: 10px;
        font-weight: 750;
        letter-spacing: .06em;
        text-transform: uppercase;
        content: attr(data-label);
    }
    .is-borrower-dashboard .borrower-active-table td.borrower-active-cell-id::before { content: none; }
    .borrower-active-purpose { max-width: none; white-space: normal; }
    .is-borrower-dashboard .borrower-active-action { width: 100%; justify-content: center; }
    .is-borrower-dashboard .borrower-active-table th:nth-child(4),
    .is-borrower-dashboard .borrower-active-table td:nth-child(4),
    .is-borrower-dashboard .borrower-active-table th:nth-child(5),
    .is-borrower-dashboard .borrower-active-table td:nth-child(5) { width: auto; }

    .is-borrower-dashboard .queue-list .borrower-next-row { padding: 14px; }
}
</style>

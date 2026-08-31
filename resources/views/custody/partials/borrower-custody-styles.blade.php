<style>
/*
|--------------------------------------------------------------------------
| Borrower - My Borrowing detail
|--------------------------------------------------------------------------
|
| One stylesheet for the borrower view of a custody transaction. The page is
| a record, not a dashboard: thin borders, one accent per state, and figures
| that line up so the borrower can check quantities at a glance.
|
| Sections: 1. Stack  2. Status  3. Summary  4. Items  5. Processing
|           6. Early return  7. Responsive
|
*/

/* 0. Back link ------------------------------------------------------------ */

.borrower-custody-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 6px;
    color: var(--interactive);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.borrower-custody-back:hover,
.borrower-custody-back:focus-visible { text-decoration: underline; }

.borrower-custody-back .ui-icon { transform: rotate(180deg); }

/* 1. Stack ---------------------------------------------------------------- */

.borrower-custody-stack {
    display: grid;
    gap: 18px;
}

.borrower-custody-stack .card { padding: 0; }

.borrower-custody-stack .card-header {
    align-items: center;
    margin: 0;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
}

.borrower-custody-stack .card-header .eyebrow {
    margin: 0 0 2px;
    font-size: 11px;
    letter-spacing: .05em;
}

.borrower-custody-stack .card-header h2 {
    margin: 0;
    font-size: 16px;
    line-height: 1.3;
}

.borrower-card-icon {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    color: var(--interactive);
}

.borrower-section-note {
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 650;
    white-space: nowrap;
}

/* 2. Status --------------------------------------------------------------- */

/*
 * A single accented line carries the state. No callout box: the state is
 * already the loudest thing on the page and does not need a second frame.
 */
.borrower-custody-status {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-left: 3px solid var(--interactive);
    border-radius: var(--radius);
}

.borrower-custody-status-icon {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    color: var(--interactive);
}

.borrower-custody-status-copy { flex: 1 1 auto; }

.borrower-custody-status-fact {
    flex: 0 0 auto;
    min-width: 0;
    padding-left: 22px;
    border-left: 1px solid var(--border);
}

.borrower-custody-status-fact small {
    display: block;
    margin-bottom: 5px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .03em;
}

.borrower-custody-status-fact strong {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--heading);
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
}

.borrower-custody-status-fact strong .ui-icon {
    flex: 0 0 auto;
    color: var(--interactive);
}

.borrower-custody-status .borrower-custody-status-action { gap: 8px; }

.borrower-custody-status.is-success { border-left-color: var(--success); }
.borrower-custody-status.is-warning { border-left-color: var(--warning); }
.borrower-custody-status.is-danger { border-left-color: var(--danger); }

.borrower-custody-status-copy { min-width: 0; }

.borrower-custody-status-copy h2 {
    margin: 0;
    color: var(--heading);
    font-size: 17px;
    font-weight: 700;
    line-height: 1.3;
}

.borrower-custody-status-copy p {
    margin: 5px 0 0;
    color: var(--text-muted);
    font-size: 13px;
    line-height: 1.5;
}

.borrower-custody-status-key {
    color: var(--text-secondary);
    font-weight: 700;
}

.borrower-custody-status .button { flex: 0 0 auto; }

/* 3. Summary -------------------------------------------------------------- */

.borrower-summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.borrower-summary-fact {
    min-width: 0;
    padding: 13px 20px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.borrower-summary-fact:nth-child(4n) { border-right: 0; }
.borrower-summary-fact:last-child { border-right: 0; }

/* The final row has no row beneath it to separate from. */
.borrower-summary-grid > .borrower-summary-fact:nth-last-child(-n + 2):nth-child(n + 5) { border-bottom: 0; }

.borrower-summary-fact small {
    display: block;
    margin-bottom: 4px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.borrower-summary-fact strong {
    display: block;
    color: var(--heading);
    font-size: 13.5px;
    font-weight: 700;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.borrower-summary-fact span {
    display: block;
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.35;
}

/* 4. Items ---------------------------------------------------------------- */

/*
 * The table is as long as the borrowing is. No fixed height and no inner
 * scrollbar: a borrower checking quantities should not have to scroll a
 * box inside a page.
 */
.borrower-items-table th,
.borrower-items-table td { padding: 10px 20px; }

.borrower-items-table th {
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    white-space: nowrap;
}

.borrower-items-table td { font-size: 13px; }

.borrower-items-table td strong {
    color: var(--heading);
    font-weight: 650;
    overflow-wrap: anywhere;
}

.borrower-items-table td small {
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

/* Quantities read as a column of figures. */
.borrower-items-table th:not(:first-child),
.borrower-items-table td:not(:first-child) {
    width: 14%;
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.borrower-items-table td.is-quantity strong {
    color: var(--interactive);
    font-size: 13.5px;
    font-weight: 750;
}

.borrower-items-table td.is-muted { color: var(--text-muted); }

/* 5. Processing ----------------------------------------------------------- */

.borrower-processing-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 16px;
    align-items: center;
    padding: 13px 20px;
    border-bottom: 1px solid var(--border);
}

.borrower-processing-row:last-child { border-bottom: 0; }

.borrower-processing-copy { min-width: 0; }

.borrower-processing-copy strong {
    display: block;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}

.borrower-processing-copy small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.45;
}

/* 6. Early return --------------------------------------------------------- */

/*
 * Optional coordination, so it stays folded away until asked for. <details>
 * carries the keyboard behaviour and expanded state natively.
 */
.borrower-early-return { padding: 0; }

.borrower-early-return-summary {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 20px;
    cursor: pointer;
    list-style: none;
}

.borrower-early-return-copy { flex: 1 1 auto; min-width: 0; }

.borrower-early-return-summary::-webkit-details-marker { display: none; }

.borrower-early-return-summary:focus-visible {
    outline: 0;
    border-radius: var(--radius);
    box-shadow: var(--focus-ring);
}

.borrower-early-return-summary strong {
    display: block;
    color: var(--heading);
    font-size: 14px;
    font-weight: 700;
}

.borrower-early-return-summary small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 12.5px;
}

.borrower-early-return-toggle {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    flex: 0 0 auto;
    color: var(--interactive);
    font-size: 12.5px;
    font-weight: 700;
}

.borrower-early-return-toggle .ui-icon { transition: transform 160ms ease; }

.borrower-early-return .is-close,
.borrower-early-return[open] .is-open { display: none; }
.borrower-early-return[open] .is-close { display: inline; }
.borrower-early-return[open] .borrower-early-return-toggle .ui-icon { transform: rotate(180deg); }

.borrower-early-return-body {
    display: grid;
    gap: 14px;
    padding: 16px 20px 18px;
    border-top: 1px solid var(--border);
}

.borrower-early-return-body label {
    display: grid;
    gap: 5px;
    margin: 0;
    color: var(--text-secondary);
    font-size: 12.5px;
    font-weight: 700;
}

.borrower-early-return-body input,
.borrower-early-return-body textarea {
    margin: 0;
    font-weight: 400;
}

.borrower-early-return-body textarea { min-height: 78px; resize: vertical; }

.borrower-early-return-body .meta {
    margin: 0;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.5;
}

.borrower-early-return-outstanding {
    width: 100%;
    min-width: 0;
    border: 1px solid var(--border);
    border-radius: 9px;
    overflow-x: auto;
}

.borrower-early-return-outstanding table {
    width: 100%;
    min-width: 480px;
    margin: 0;
    border-collapse: collapse;
    table-layout: fixed;
}

.borrower-early-return-outstanding th,
.borrower-early-return-outstanding td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 12.5px;
    line-height: 1.5;
    text-align: left;
    vertical-align: middle;
}

.borrower-early-return-outstanding th + th,
.borrower-early-return-outstanding td + td { border-left: 1px solid var(--border); }

.borrower-early-return-outstanding th {
    color: var(--text-secondary);
    background: var(--table-heading-bg);
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.borrower-early-return-outstanding th:first-child { width: 60%; }
.borrower-early-return-outstanding th:nth-child(2) { width: 16%; }
.borrower-early-return-outstanding td { background: var(--surface-elevated); }
.borrower-early-return-outstanding td strong { color: var(--heading); font-weight: 650; overflow-wrap: anywhere; }
.borrower-early-return-outstanding td:nth-child(2) { color: var(--text-muted); overflow-wrap: anywhere; }
.borrower-early-return-outstanding tbody tr:last-child td { border-bottom: 0; }
.borrower-early-return-outstanding td.is-quantity { color: var(--heading); font-weight: 700; }

.borrower-early-return-outstanding th:last-child,
.borrower-early-return-outstanding td:last-child {
    width: 24%;
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.borrower-early-return-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Active coordination notice */
.borrower-early-return-notice {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 13px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-left: 3px solid var(--info);
    border-radius: var(--radius);
}

.borrower-early-return-notice strong {
    display: block;
    color: var(--heading);
    font-size: 13px;
    font-weight: 700;
}

.borrower-early-return-notice small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.45;
}

/* 7. Responsive ----------------------------------------------------------- */

@media (max-width: 1050px) {
    .borrower-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .borrower-summary-fact:nth-child(4n) { border-right: 1px solid var(--border); }
    .borrower-summary-fact:nth-child(2n) { border-right: 0; }
}

@media (max-width: 700px) {
    .borrower-custody-status,
    .borrower-early-return-summary,
    .borrower-early-return-notice {
        align-items: flex-start;
        flex-direction: column;
        gap: 12px;
    }

    .borrower-custody-status-fact {
        padding-left: 0;
        border-left: 0;
    }

    .borrower-summary-grid { grid-template-columns: 1fr; }

    .borrower-summary-fact,
    .borrower-summary-fact:nth-child(2n),
    .borrower-summary-fact:nth-child(4n) { border-right: 0; }

    .borrower-processing-row {
        grid-template-columns: 1fr;
        justify-items: start;
        gap: 9px;
    }

    .borrower-items-table th,
    .borrower-items-table td { padding: 10px 14px; }
}
</style>

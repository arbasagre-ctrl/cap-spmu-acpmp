<style>
/*
| Overview-only cross-tab summary.
| Kept in its own partial so this update does not replace or restyle the
| universal Analytics theme, KPI cards, charts, filters or other sections.
*/
.analytics-summary-shell { overflow: hidden; }

.analytics-summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
    padding: 12px;
}

.analytics-summary-card {
    position: relative;
    display: grid;
    grid-template-columns: 30px minmax(0, 1fr) 16px;
    align-items: center;
    gap: 8px 10px;
    min-width: 0;
    padding: 13px 14px;
    color: inherit;
    background: var(--surface-elevated);
    border: 1px solid var(--row-border);
    border-radius: 11px;
    text-decoration: none;
    transition: border-color var(--motion-fast, .12s) ease, box-shadow var(--motion-fast, .12s) ease;
}

.analytics-summary-card:hover,
.analytics-summary-card:focus-visible {
    border-color: var(--interactive);
    box-shadow: var(--shadow-sm);
}

.analytics-summary-card:focus-visible {
    outline: 2px solid var(--interactive);
    outline-offset: 2px;
}

.analytics-summary-icon {
    display: grid;
    grid-column: 1;
    grid-row: 1;
    place-items: center;
    width: 30px;
    height: 30px;
    color: var(--interactive);
    background: var(--blue-50);
    border-radius: 8px;
}

.analytics-summary-title {
    grid-column: 2;
    grid-row: 1;
    min-width: 0;
    color: var(--heading);
    font-size: 13px;
    font-weight: 760;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.analytics-summary-arrow {
    grid-column: 3;
    grid-row: 1;
    justify-self: end;
    color: var(--text-muted);
}

.analytics-summary-metrics {
    display: grid;
    grid-column: 1 / -1;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0;
    margin: 2px 0 0;
}

.analytics-summary-metrics > div {
    display: grid;
    align-content: start;
    min-width: 0;
    padding: 7px 10px;
    border-left: 1px solid var(--row-border);
}

.analytics-summary-metrics > div:first-child {
    padding-left: 0;
    border-left: 0;
}

.analytics-summary-metrics dt {
    margin: 0;
    color: var(--text-muted);
    font-size: 9.5px;
    font-weight: 700;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.analytics-summary-metrics dd {
    margin: 3px 0 0;
    color: var(--heading);
    font-size: 18px;
    font-weight: 790;
    line-height: 1.15;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}

.analytics-summary-metrics dd.is-text {
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
    line-height: 1.3;
}

.analytics-summary-status {
    grid-column: 1 / -1;
    min-width: 0;
    padding-top: 7px;
    color: var(--text-muted);
    border-top: 1px solid var(--row-border);
    font-size: 10.5px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

@media (max-width: 980px) {
    .analytics-summary-grid { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 620px) {
    .analytics-summary-metrics { grid-template-columns: minmax(0, 1fr); }

    .analytics-summary-metrics > div,
    .analytics-summary-metrics > div:first-child {
        padding: 7px 0;
        border-left: 0;
        border-top: 1px solid var(--row-border);
    }

    .analytics-summary-metrics > div:first-child { border-top: 0; }
}
</style>

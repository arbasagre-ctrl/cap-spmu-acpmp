<style>
/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| The page answers one question per section. Everything here serves reading
| speed: large figures, a bar you can compare at a glance, and a sentence
| that says what the picture means so nobody has to interpret a chart.
|
| Sections: 1. Shell  2. Filters  3. Question card  4. Figures
|           5. Bars  6. Split columns  7. Insights  8. Responsive
|
*/

/* 1. Shell ---------------------------------------------------------------- */

.analytics-page { display: grid; gap: 18px; }

/* 2. Filters -------------------------------------------------------------- */

.analytics-filters {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    padding: 16px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
}

.analytics-filters label {
    display: grid;
    gap: 6px;
    margin: 0;
    min-width: 0;
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 700;
}

.analytics-filters select {
    width: 100%;
    min-height: 42px;
    margin: 0;
    font-size: 13px;
    font-weight: 400;
}

.analytics-period-note {
    grid-column: 1 / -1;
    margin: 0;
    color: var(--text-muted);
    font-size: 12px;
}

/* 3. Question card -------------------------------------------------------- */

.analytics-section {
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.analytics-section > h2 {
    margin: 0;
    padding: 15px 20px;
    color: var(--heading);
    font-size: 16px;
    font-weight: 700;
    border-bottom: 1px solid var(--border);
}

.analytics-section-body { padding: 18px 20px; }

/*
 * The reading of the section, in one sentence. It sits directly under the
 * figures it explains rather than in a separate commentary block.
 */
.analytics-reading {
    margin: 0;
    padding: 13px 20px;
    color: var(--text-secondary);
    background: var(--surface-subtle);
    border-top: 1px solid var(--border);
    font-size: 13px;
    line-height: 1.55;
}

.analytics-empty {
    margin: 0;
    padding: 13px 20px;
    color: var(--text-muted);
    background: var(--surface-subtle);
    font-size: 13px;
    line-height: 1.5;
}

/* With nothing to show, the heading and the reason belong on one line. */
.analytics-section.is-empty > h2 { border-bottom: 0; }

.analytics-section.is-empty {
    display: grid;
    grid-template-columns: minmax(0, auto) minmax(0, 1fr);
    align-items: center;
}

.analytics-section.is-empty > h2 { padding-right: 0; }

.analytics-section.is-empty .analytics-empty { background: transparent; }

/* 4. Figures -------------------------------------------------------------- */

.analytics-figures {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.analytics-figure {
    min-width: 0;
    padding: 18px 20px;
    border-right: 1px solid var(--border);
}

.analytics-figure:last-child { border-right: 0; }

.analytics-figure strong {
    display: block;
    color: var(--heading);
    font-size: 30px;
    font-weight: 750;
    line-height: 1.05;
    letter-spacing: -.02em;
    font-variant-numeric: tabular-nums;
}

.analytics-figure span {
    display: block;
    margin-top: 5px;
    color: var(--text-secondary);
    font-size: 13px;
    font-weight: 650;
    line-height: 1.35;
}

.analytics-figure small {
    display: block;
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.35;
}

/* An attention figure is tinted only when it is actually above zero. */
.analytics-figure.is-attention strong { color: var(--danger); }

/*
 * Inventory is a summary of another module, so its figures are deliberately
 * quieter than the operational ones at the top of the page.
 */
.analytics-figures.is-secondary .analytics-figure strong { font-size: 22px; }
.analytics-figures.is-secondary .analytics-figure { padding: 15px 20px; }

/* 5. Bars ----------------------------------------------------------------- */

.analytics-bars { display: grid; gap: 13px; }

.analytics-bar-row { display: grid; gap: 6px; min-width: 0; }

.analytics-bar-head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 14px;
}

.analytics-bar-name {
    color: var(--heading);
    font-size: 13px;
    font-weight: 650;
    overflow-wrap: anywhere;
}

/* The number is always written out, never left to the bar alone. */
.analytics-bar-value {
    flex: 0 0 auto;
    color: var(--text-secondary);
    font-size: 12.5px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.analytics-bars.is-single .analytics-bar-track { display: none; }

.analytics-bars.is-single .analytics-bar-head { align-items: baseline; }

.analytics-bar-track {
    height: 10px;
    background: var(--surface-muted);
    border-radius: 999px;
    overflow: hidden;
}

.analytics-bar-fill {
    height: 100%;
    background: var(--interactive);
    border-radius: 999px;
}

.analytics-bar-row.is-academic .analytics-bar-fill { background: #1769e0; }
.analytics-bar-row.is-administration .analytics-bar-fill { background: #0e7c66; }
.analytics-bar-row.is-research .analytics-bar-fill { background: #7a4bc4; }

/* 6. Split columns -------------------------------------------------------- */

.analytics-split {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
}

.analytics-split > section {
    min-width: 0;
    padding: 18px 20px;
    border-right: 1px solid var(--border);
}

.analytics-split > section:last-child { border-right: 0; }

.analytics-split h3 {
    margin: 0 0 14px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
}

/* 7. Insights ------------------------------------------------------------- */

.analytics-insights {
    display: grid;
    gap: 0;
}

.analytics-insight {
    display: grid;
    grid-template-columns: 22px minmax(0, 1fr);
    gap: 12px;
    align-items: start;
    padding: 13px 20px;
    border-bottom: 1px solid var(--row-border);
    color: var(--text-secondary);
    font-size: 13px;
    line-height: 1.55;
}

.analytics-insight:last-child { border-bottom: 0; }

.analytics-insight-mark {
    display: grid;
    place-items: center;
    width: 22px;
    height: 22px;
    color: var(--interactive);
    background: var(--blue-50);
    border-radius: 50%;
    font-size: 11px;
    font-weight: 750;
    font-variant-numeric: tabular-nums;
}

/* 8. Responsive ----------------------------------------------------------- */

@media (max-width: 1050px) {
    .analytics-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-figures { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-figure:nth-child(2n) { border-right: 0; }
    .analytics-figure:nth-child(-n + 2) { border-bottom: 1px solid var(--border); }
}

@media (max-width: 760px) {
    /* Too narrow to keep the question and its reason side by side. */
    .analytics-section.is-empty { grid-template-columns: 1fr; }
    .analytics-section.is-empty > h2 { padding-bottom: 4px; }
    .analytics-section.is-empty .analytics-empty { padding-top: 0; }
}

@media (max-width: 620px) {
    .analytics-filters,
    .analytics-figures { grid-template-columns: 1fr; }

    .analytics-figure {
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }

    .analytics-figure:last-child { border-bottom: 0; }

    .analytics-split > section {
        border-right: 0;
        border-bottom: 1px solid var(--border);
    }

    .analytics-split > section:last-child { border-bottom: 0; }
}
</style>

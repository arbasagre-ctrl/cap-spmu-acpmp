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

/* ---------------------------------------------------------------- */
/* Section navigation                                                */
/* ---------------------------------------------------------------- */

.analytics-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    padding: 6px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
}

.analytics-tab {
    padding: 9px 16px;
    border-radius: 7px;
    color: var(--text-secondary);
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: color var(--motion-fast) ease, background-color var(--motion-fast) ease;
}

.analytics-tab:hover { color: var(--interactive); background: var(--surface-hover); }

.analytics-tab.is-active {
    color: #fff;
    background: var(--primary-action);
}

html[data-theme="dark"] .analytics-tab.is-active { color: #fff; }

/* ---------------------------------------------------------------- */
/* Headline figures                                                  */
/* ---------------------------------------------------------------- */

.analytics-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
}

.analytics-kpi {
    display: grid;
    gap: 5px;
    align-content: start;
    padding: 18px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    min-width: 0;
}

.analytics-kpi-label {
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.analytics-kpi > strong {
    color: var(--heading);
    font-size: 30px;
    font-weight: 750;
    line-height: 1.1;
}

/* A predicted group or unit name, not a count. */
.analytics-kpi > strong.is-text {
    font-size: 17px;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.analytics-kpi > small {
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.5;
}

/* Attention is carried by the label too, never by colour alone. */
.analytics-kpi.is-attention { border-color: var(--danger-border); }
.analytics-kpi.is-attention > strong { color: var(--danger); }

/* ---------------------------------------------------------------- */
/* Overview headline figures: colour-coded and clickable             */
/* ---------------------------------------------------------------- */

/*
 * Each Overview figure links to the record page that lists what it counts.
 * The tone is carried by the top border, the icon, and the label; the count
 * itself stays navy so it is the easiest thing on the card to read.
 */
.analytics-kpi-link {
    --kpi-tone: var(--interactive);
    --kpi-tone-bg: var(--blue-50);
    --kpi-tone-border: var(--info-border);

    position: relative;
    gap: 8px;
    padding: 16px 18px 18px;
    border-top: 3px solid var(--kpi-tone);
    color: inherit;
    text-decoration: none;
    cursor: pointer;
    transition:
        border-color var(--motion) ease,
        box-shadow var(--motion) ease,
        background-color var(--motion) ease,
        transform var(--motion) ease;
}

.analytics-kpi-link.tone-requests {
    --kpi-tone: #0f62d6;
    --kpi-tone-bg: #eaf2fd;
    --kpi-tone-border: #bcd6f7;
}

.analytics-kpi-link.tone-custody {
    --kpi-tone: #0b7285;
    --kpi-tone-bg: #e4f5f8;
    --kpi-tone-border: #b3dde5;
}

.analytics-kpi-link.tone-followup {
    --kpi-tone: #b45309;
    --kpi-tone-bg: #fdf3e4;
    --kpi-tone-border: #f0d6ab;
}

.analytics-kpi-link.tone-stock {
    --kpi-tone: #b42318;
    --kpi-tone-bg: #fdeceb;
    --kpi-tone-border: #f2c2be;
}

.analytics-kpi-top {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.analytics-kpi-icon {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    flex-shrink: 0;
    border: 1px solid var(--kpi-tone-border);
    border-radius: 8px;
    background: var(--kpi-tone-bg);
    color: var(--kpi-tone);
}

.analytics-kpi-link .analytics-kpi-label {
    min-width: 0;
    color: var(--kpi-tone);
    overflow-wrap: anywhere;
}

.analytics-kpi-arrow {
    flex-shrink: 0;
    margin-left: auto;
    color: var(--text-soft);
    transition: transform var(--motion) ease, color var(--motion) ease;
}

/* The count stays navy at every tone, including the attention ones. */
.analytics-kpi-link > strong { color: var(--heading); }

.analytics-kpi-link:hover,
.analytics-kpi-link:focus-visible {
    background: var(--kpi-tone-bg);
    border-color: var(--kpi-tone-border);
    border-top-color: var(--kpi-tone);
    box-shadow: 0 8px 20px rgba(7, 27, 53, .08);
    transform: translateY(-1px);
}

.analytics-kpi-link:hover .analytics-kpi-arrow,
.analytics-kpi-link:focus-visible .analytics-kpi-arrow {
    color: var(--kpi-tone);
    transform: translateX(2px);
}

.analytics-kpi-link:focus-visible {
    outline: none;
    box-shadow: var(--focus-ring);
}

html[data-theme="dark"] .analytics-kpi-link.tone-requests {
    --kpi-tone: #72b7f4;
    --kpi-tone-bg: #14263c;
    --kpi-tone-border: #2c4c72;
}

html[data-theme="dark"] .analytics-kpi-link.tone-custody {
    --kpi-tone: #6fc9da;
    --kpi-tone-bg: #102b31;
    --kpi-tone-border: #2b5a63;
}

html[data-theme="dark"] .analytics-kpi-link.tone-followup {
    --kpi-tone: #f3c56a;
    --kpi-tone-bg: #2e2411;
    --kpi-tone-border: #6b5327;
}

html[data-theme="dark"] .analytics-kpi-link.tone-stock {
    --kpi-tone: #ff9b93;
    --kpi-tone-bg: #33191b;
    --kpi-tone-border: #6f3c40;
}

@media (prefers-reduced-motion: reduce) {
    .analytics-kpi-link,
    .analytics-kpi-arrow { transition: none; }
    .analytics-kpi-link:hover,
    .analytics-kpi-link:focus-visible { transform: none; }
}

/* ---------------------------------------------------------------- */
/* Status wording                                                    */
/* ---------------------------------------------------------------- */

.analytics-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    font-size: 10.5px;
    font-weight: 750;
    letter-spacing: .02em;
    white-space: nowrap;
}

.analytics-status.is-sufficient,
.analytics-status.is-normal { color: var(--success); background: var(--success-bg); }

.analytics-status.is-limited,
.analytics-status.is-moderate { color: var(--warning); background: var(--warning-bg); }

.analytics-status.is-possible-shortage,
.analytics-status.is-unavailable,
.analytics-status.is-high { color: var(--danger); background: var(--danger-bg); }

.analytics-tag {
    display: inline-block;
    margin-left: 7px;
    padding: 2px 7px;
    border-radius: 999px;
    background: var(--surface-muted);
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 750;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.analytics-bar-row.is-forecast .analytics-bar-fill {
    background: repeating-linear-gradient(
        135deg,
        var(--interactive),
        var(--interactive) 6px,
        transparent 6px,
        transparent 12px
    ), var(--interactive);
    opacity: .85;
}

.analytics-bar-row.is-forecast .analytics-tag {
    background: var(--info-bg);
    color: var(--info);
}

/* ---------------------------------------------------------------- */
/* Tables and supporting copy                                        */
/* ---------------------------------------------------------------- */

.analytics-table-scroll { overflow-x: auto; }

.analytics-table {
    width: 100%;
    min-width: 480px;
    margin: 0;
    border-collapse: collapse;
}

.analytics-table th,
.analytics-table td {
    padding: 11px 12px;
    border-bottom: 1px solid var(--row-border);
    font-size: 12.5px;
    text-align: left;
    vertical-align: top;
}

.analytics-table thead th {
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
    white-space: nowrap;
}

.analytics-table tbody th { color: var(--heading); font-weight: 700; }
.analytics-table tbody th small { display: block; margin-top: 3px; color: var(--text-muted); font-size: 11px; font-weight: 500; }
.analytics-table .is-numeric { text-align: right; font-variant-numeric: tabular-nums; }
.analytics-table tbody tr:last-child th,
.analytics-table tbody tr:last-child td { border-bottom: 0; }

.analytics-metric-note {
    margin: 0 0 13px;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.5;
}

.analytics-action { margin: 12px 0 0; font-size: 12.5px; font-weight: 700; }
.analytics-action a { color: var(--interactive); }

.analytics-watch {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 15px 17px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: 10px;
}

.analytics-watch strong { display: block; color: var(--heading); font-size: 14.5px; }
.analytics-watch span { color: var(--text-muted); font-size: 12.5px; }

.analytics-forecast-window {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    padding: 16px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
}

.analytics-forecast-window strong {
    display: block;
    margin-top: 4px;
    color: var(--heading);
    font-size: 14px;
}

.analytics-details { margin-top: 12px; font-size: 12px; }
.analytics-details summary { color: var(--interactive); cursor: pointer; font-weight: 700; }
.analytics-details ul { margin: 10px 0 0; padding-left: 20px; color: var(--text-secondary); line-height: 1.7; }

@media (max-width: 1050px) {
    .analytics-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
    .analytics-kpis,
    .analytics-forecast-window { grid-template-columns: 1fr; }

    .analytics-tabs { flex-wrap: nowrap; overflow-x: auto; }
}
</style>

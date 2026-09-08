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

/*
 * A bar row that drills down. It keeps the same shape as a static row and
 * only gains a hit area, a hover surface, and a focus ring.
 */
.analytics-bar-link {
    display: grid;
    gap: 6px;
    padding: 9px 11px;
    border: 1px solid transparent;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
    transition: background-color var(--motion) ease, border-color var(--motion) ease;
}

.analytics-bar-link:hover {
    background: var(--surface-subtle);
    border-color: var(--border);
}

.analytics-bar-link:focus-visible {
    outline: none;
    border-color: var(--interactive);
    box-shadow: var(--focus-ring);
}

/* The row the current filters resolve to. */
.analytics-bar-link.is-selected {
    background: var(--blue-50);
    border-color: var(--info-border);
}

.analytics-bar-link.is-selected .analytics-bar-name { font-weight: 750; }

@media (prefers-reduced-motion: reduce) {
    .analytics-bar-link { transition: none; }
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

<style>
/*
| Analytics restructure additions.
|
| Kept to the existing SPMU-ACPMP language: white cards, navy headings, blue
| accent, thin borders, restrained shadows. Nothing here introduces a new
| colour system - semantic tones reuse the application tokens.
*/

/* The limitation notice above the tabs. */
.analytics-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0 0 14px;
    padding: 13px 15px;
    background: var(--warning-bg);
    border: 1px solid var(--warning-border);
    border-radius: var(--radius);
    color: var(--warning);
    font-size: 12.5px;
    line-height: 1.55;
}
.analytics-notice .ui-icon { flex: 0 0 auto; margin-top: 1px; }

/* Short provenance line under a KPI figure. */
.analytics-kpi-basis {
    display: block;
    margin-top: 6px;
    padding-top: 7px;
    border-top: 1px solid var(--row-border);
    color: var(--text-soft);
    font-size: 10.5px;
    line-height: 1.4;
}

/* Vertical trend chart. */
.analytics-trend {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    min-height: 190px;
    padding: 12px 2px 0;
    overflow-x: auto;
}
.analytics-trend-col { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 7px; min-width: 54px; flex: 1 1 0; height: 170px; }
.analytics-trend-bar { position: relative; display: block; width: 100%; max-width: 46px; min-height: 3px; background: var(--interactive); border-radius: 4px 4px 0 0; }
.analytics-trend-count { position: absolute; top: -18px; left: 50%; transform: translateX(-50%); color: var(--heading); font-size: 11px; font-weight: 750; font-variant-numeric: tabular-nums; }
.analytics-trend-label { color: var(--text-muted); font-size: 10.5px; text-align: center; line-height: 1.3; }

/* Compact figure grid used by the health and returns tabs. */
.analytics-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 12px; margin-bottom: 14px; }
.analytics-stat {
    display: grid;
    gap: 5px;
    padding: 13px 15px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: inherit;
    text-decoration: none;
}
.analytics-stat > span { color: var(--text-muted); font-size: 11px; font-weight: 700; letter-spacing: .02em; }
.analytics-stat > strong { color: var(--heading); font-size: 21px; font-weight: 750; font-variant-numeric: tabular-nums; }
.analytics-stat > strong.is-text { font-size: 14px; }
a.analytics-stat:hover { border-color: var(--interactive); background: var(--surface-hover); }
a.analytics-stat:focus-visible { outline: 0; box-shadow: var(--focus-ring); }
.analytics-stat.is-static { background: var(--surface-elevated); }

/* Tables inside analytics sections. */
.analytics-table-scroll { width: 100%; overflow-x: auto; }
.analytics-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.analytics-table th {
    padding: 8px 10px;
    text-align: left;
    color: var(--text-secondary);
    background: var(--table-heading-bg);
    border-bottom: 1px solid var(--border-strong);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
    white-space: nowrap;
}
.analytics-table td { padding: 8px 10px; border-top: 1px solid var(--row-border); color: var(--text); vertical-align: top; }
.analytics-table td.numeric, .analytics-table th.numeric { text-align: right; font-variant-numeric: tabular-nums; }
.analytics-table a { color: var(--interactive); text-decoration: none; }
.analytics-table a:hover { text-decoration: underline; }

/* Status tag - readable as words first, tone second. */
.analytics-tag {
    display: inline-flex;
    align-items: center;
    min-height: 20px;
    padding: 2px 8px;
    border: 1px solid var(--neutral-border);
    border-radius: 999px;
    background: var(--neutral-bg);
    color: var(--neutral);
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}
.analytics-tag.is-positive { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
.analytics-tag.is-attention { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
.analytics-tag.is-critical { color: var(--danger); background: var(--danger-bg); border-color: var(--danger-border); }

/* "How this was calculated". */
.analytics-explain { margin: 14px 0 0; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface-subtle); }
.analytics-explain > summary { padding: 10px 13px; cursor: pointer; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.analytics-explain > div { display: grid; gap: 7px; padding: 0 13px 13px; }
.analytics-explain p { margin: 0; color: var(--text-secondary); font-size: 12px; line-height: 1.55; }
.analytics-formula { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; color: var(--heading) !important; font-size: 12.5px !important; }

.analytics-metric-note-spaced { margin-top: 18px; }
.analytics-bar-name small { display: block; margin-top: 2px; color: var(--text-muted); font-size: 10.5px; font-weight: 400; }

/* Tabs stay usable on a narrow screen. */
.analytics-tabs { overflow-x: auto; scrollbar-width: thin; }
.analytics-tab { white-space: nowrap; }

@media (max-width: 700px) {
    .analytics-trend-col { min-width: 44px; }
    .analytics-stat-grid { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
}
</style>

<style>
/*
| Analytics detail panel.
|
| A detail is part of Analytics, not a jump to another module, so it opens
| over the section that produced the figure and closes back onto it. White
| surface, compact heading, one ranking or table, and the single handoff to
| Reports at the foot. It is deliberately not another dashboard.
*/
.analytics-detail-overlay {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    justify-content: flex-end;
}

.analytics-detail-scrim {
    position: absolute;
    inset: 0;
    background: rgba(7, 27, 53, .42);
    display: block;
}

.analytics-detail {
    position: relative;
    display: flex;
    flex-direction: column;
    width: min(660px, 100%);
    max-height: 100%;
    background: var(--surface-elevated);
    border-left: 1px solid var(--border);
    box-shadow: var(--shadow);
}

.analytics-detail:focus { outline: 0; }

.analytics-detail-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 18px 20px 14px;
    border-bottom: 1px solid var(--border);
}
.analytics-detail-context { margin: 0; color: var(--text-muted); font-size: 10.5px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
.analytics-detail-head h2 { margin: 4px 0 0; color: var(--heading); font-size: 17px; font-weight: 750; line-height: 1.3; }
.analytics-detail-head h2:focus { outline: 0; }
.analytics-detail-scope { margin: 5px 0 0; color: var(--text-muted); font-size: 11.5px; }
.analytics-detail-close { flex: 0 0 auto; }

.analytics-detail-body { display: grid; gap: 14px; padding: 18px 20px; overflow-y: auto; }

.analytics-detail-figure { display: flex; align-items: baseline; gap: 9px; margin: 0; }
.analytics-detail-figure strong { color: var(--heading); font-size: 30px; font-weight: 750; line-height: 1; font-variant-numeric: tabular-nums; }
.analytics-detail-figure span { color: var(--text-muted); font-size: 12.5px; }

.analytics-detail-note {
    margin: 0;
    padding: 11px 13px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-secondary);
    font-size: 12px;
    line-height: 1.6;
}

.analytics-detail-subhead { margin: 4px 0 0; color: var(--text-secondary); font-size: 11px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }

.analytics-detail-body .analytics-stat-grid { margin-bottom: 0; }
.analytics-detail-body .analytics-empty { margin: 0; }

.analytics-detail-foot {
    display: flex;
    justify-content: flex-end;
    padding: 14px 20px;
    border-top: 1px solid var(--border);
    background: var(--surface-subtle);
}
.analytics-detail-foot .button { gap: 7px; }

/* Section-level handoff, used where a group of figures shares one source. */
.analytics-section-action { margin: 12px 0 0; }
.analytics-section-action a { display: inline-flex; align-items: center; gap: 5px; color: var(--interactive); font-size: 12px; font-weight: 700; text-decoration: none; }
.analytics-section-action a:hover { text-decoration: underline; }

/* Trend bars became links, so they need their own affordance. */
a.analytics-trend-col { text-decoration: none; border-radius: 6px; transition: background-color var(--motion) ease; }
a.analytics-trend-col:hover { background: var(--surface-hover); }
a.analytics-trend-col:focus-visible { outline: 0; box-shadow: var(--focus-ring); }
a.analytics-trend-col:hover .analytics-trend-bar { background: var(--interactive-hover); }

@media (max-width: 720px) {
    .analytics-detail { width: 100%; border-left: 0; }
    .analytics-detail-figure strong { font-size: 26px; }
}

@media (prefers-reduced-motion: reduce) {
    a.analytics-trend-col { transition: none; }
}
</style>

<style>
/*
| Browser QA fixes.
|
| Each rule below answers something measured in the rendered page rather than
| guessed at from the markup.
*/

/*
| F3 - a period with one bucket stretched its column to the full row width,
| leaving a single bar adrift in 1099px of empty space. Columns now take their
| natural width and the row starts at the left.
*/
.analytics-trend { justify-content: flex-start; }
.analytics-trend-col { flex: 0 1 96px; max-width: 96px; }

/*
| F5 - the detail footer sat directly under the content, leaving a tall blank
| area beneath it in a full-height panel. The body now takes the slack so the
| action stays at the foot of the surface.
*/
.analytics-detail-body { flex: 1 1 auto; min-height: 0; }

/* F6 - measured 660px; the analytical drawer reads better nearer 800. */
.analytics-detail { width: min(820px, 100%); }

@media (max-width: 720px) {
    .analytics-detail { width: 100%; }
}
</style>

<style>
/*
| Tab QA fixes.
|
| Measured in the rendered page, not inferred from the markup.
*/

/*
| T1 - every ranking bar was invisible.
|
| Measured: .analytics-bar-fill computed to 0x0 with display:inline on all five
| tabs, so its width:100% and background were never painted. The track escapes
| the same fate only because it is a grid item of .analytics-bar-row and is
| blockified; the fill sits one level deeper, inside the track, where nothing
| blockifies it. Making it a block is the whole fix.
*/
.analytics-bar-fill { display: block; min-width: 2px; }

/*
| T2 - a bar carrying a real value should never render as a bare track. A
| hairline keeps a very small share visible against the rounded track.
*/
.analytics-bar-track { position: relative; }

/*
| T3 - Predictive mixes available and withheld projections in one view,
| because each is guarded on its own history. The note says so, quietly.
*/
.analytics-guard-note {
    margin: -4px 0 14px;
    padding: 10px 14px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.55;
}

/*
| T4 - every block in the detail drawer rendered far taller than its text.
|
| Measured on Equipment Detail: body 733px holding roughly 430px of content,
| with a 2-line note occupying 166px and a 1-line empty state 149px. The body
| is a grid, and the earlier flex:1 1 auto that pins the footer to the foot of
| the panel also handed the grid surplus height, which its default
| align-content:normal then shared out among the auto rows. Packing the rows to
| the start keeps the pinned footer without inflating what sits above it.
*/
.analytics-detail-body { align-content: start; }
</style>

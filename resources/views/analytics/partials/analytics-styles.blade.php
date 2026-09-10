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

/*
| F6 set this drawer to 820px. That was too wide: at 1440 it covered 57% of the
| viewport and read as a full-page modal rather than a quick inspection. The
| width now lives in one place, at the foot of this file.
*/
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

/* ======================================================================== */
/* Overview - executive dashboard                                           */
/*                                                                          */
/* Presentation only. Nothing here reads or changes a figure; the colour     */
/* lives on the KPI row and the icon marks, and every reading surface below  */
/* it stays a plain white card so the hierarchy is unambiguous.              */
/* ======================================================================== */

/* Shell ------------------------------------------------------------------ */

.analytics-page { display: grid; gap: 13px; }

.analytics-heading { margin-bottom: 13px; }
.analytics-heading h1 { margin: 1px 0 3px; font-size: clamp(20px, 1.5vw, 24px); }
.analytics-heading p:not(.eyebrow) { font-size: 12.5px; line-height: 1.45; }
.analytics-heading .eyebrow { margin-bottom: 2px; }

/* Tabs ------------------------------------------------------------------- */

.analytics-page .analytics-tabs {
    display: flex;
    gap: 3px;
    padding: 4px;
    border-radius: 11px;
    box-shadow: none;
}

.analytics-page .analytics-tab {
    flex: 1 1 0;
    min-height: 36px;
    padding: 0 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    font-size: 12.5px;
    text-align: center;
}

.analytics-page .analytics-tab.is-active {
    background: var(--primary-action);
    box-shadow: 0 3px 10px rgba(23, 105, 170, .25);
}

/* Filters ---------------------------------------------------------------- */
/*
| Three cards rather than one form panel. The controls, their names and their
| submit behaviour are untouched; only the frame around them changed.
*/
.analytics-page .analytics-filters {
    padding: 0;
    background: transparent;
    border: 0;
    border-radius: 0;
    box-shadow: none;
    gap: 12px;
}

.analytics-page .analytics-filters label {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    grid-template-rows: auto auto;
    align-content: center;
    row-gap: 2px;
    padding: 8px 12px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 9px;
    box-shadow: none;
}

/* Name above value, nothing beside it - the row carries no icon column. */
.analytics-filter-label {
    display: block;
    min-width: 0;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .04em;
    line-height: 1.3;
    text-transform: uppercase;
    overflow-wrap: anywhere;
}

.analytics-page .analytics-filters select {
    min-height: 32px;
    padding-top: 0;
    padding-bottom: 0;
    padding-left: 0;
    border-color: transparent;
    background-color: transparent;
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 650;
}

.analytics-page .analytics-filters select:hover { background-color: var(--surface-subtle); }
.analytics-page .analytics-filters select:focus { padding-left: 9px; border-color: var(--interactive); background-color: var(--input-bg); }

.analytics-page .analytics-period-note {
    grid-column: 1 / -1;
    margin: -4px 0 0;
    padding-left: 2px;
    color: var(--text-soft);
    font-size: 10.5px;
    line-height: 1.35;
}

/* KPI row ---------------------------------------------------------------- */

.analytics-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 13px;
}

.analytics-kpi-card {
    --kpi-from: #2563eb;
    --kpi-to: #38bdf8;
    --kpi-glow: rgba(37, 99, 235, .3);

    position: relative;
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr) 14px;
    grid-template-rows: auto auto auto auto;
    align-content: center;
    column-gap: 11px;
    row-gap: 1px;
    min-width: 0;
    min-height: 118px;
    padding: 14px 15px;
    overflow: hidden;
    background: linear-gradient(135deg, var(--kpi-from) 0%, var(--kpi-to) 100%);
    border: 0;
    border-radius: 13px;
    box-shadow: 0 4px 14px var(--kpi-glow);
    color: #fff;
    text-decoration: none;
    transition: box-shadow var(--motion) ease, transform var(--motion) ease;
}

/* A soft highlight so a flat fill does not read as a solid colour block. */
.analytics-kpi-card::after {
    content: '';
    position: absolute;
    top: -46px;
    right: -34px;
    width: 132px;
    height: 132px;
    border-radius: 50%;
    background: rgba(255, 255, 255, .13);
    pointer-events: none;
}

.analytics-kpi-card > * { position: relative; z-index: 1; }

.analytics-kpi-card-icon {
    display: grid;
    grid-column: 1;
    grid-row: 1 / span 2;
    place-items: center;
    width: 38px;
    height: 38px;
    color: #fff;
    background: rgba(255, 255, 255, .2);
    border: 1px solid rgba(255, 255, 255, .26);
    border-radius: 10px;
}

.analytics-kpi-card-label {
    grid-column: 2;
    grid-row: 1;
    align-self: end;
    min-width: 0;
    color: rgba(255, 255, 255, .92);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .07em;
    text-transform: uppercase;
    overflow-wrap: anywhere;
}

.analytics-kpi-card-value {
    grid-column: 2;
    grid-row: 2;
    color: #fff;
    font-size: 28px;
    font-weight: 780;
    line-height: 1.05;
    font-variant-numeric: tabular-nums;
}

.analytics-kpi-card-note {
    grid-column: 1 / -1;
    grid-row: 3;
    margin-top: 9px;
    color: rgba(255, 255, 255, .95);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.35;
}

.analytics-kpi-card-meta {
    grid-column: 1 / -1;
    grid-row: 4;
    margin-top: 2px;
    overflow: hidden;
    color: rgba(255, 255, 255, .72);
    font-size: 10px;
    line-height: 1.35;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.analytics-kpi-card-arrow {
    grid-column: 3;
    grid-row: 1 / span 2;
    align-self: center;
    color: rgba(255, 255, 255, .8);
    transition: transform var(--motion) ease;
}

.analytics-kpi-card:hover,
.analytics-kpi-card:focus-visible {
    box-shadow: 0 10px 24px var(--kpi-glow);
    transform: translateY(-2px);
}

.analytics-kpi-card:hover .analytics-kpi-card-arrow { transform: translateX(2px); }
.analytics-kpi-card:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }

.analytics-kpi-card.tone-requests { --kpi-from: #2563eb; --kpi-to: #38bdf8; --kpi-glow: rgba(37, 99, 235, .3); }
.analytics-kpi-card.tone-custody  { --kpi-from: #6d28d9; --kpi-to: #6366f1; --kpi-glow: rgba(109, 40, 217, .3); }
.analytics-kpi-card.tone-overdue  { --kpi-from: #c2410c; --kpi-to: #f59e0b; --kpi-glow: rgba(194, 65, 12, .28); }
.analytics-kpi-card.tone-stock    { --kpi-from: #dc2626; --kpi-to: #fb7185; --kpi-glow: rgba(220, 38, 38, .28); }

/* Deeper on dark so the fills sit in the page rather than glaring off it. */
html[data-theme="dark"] .analytics-kpi-card.tone-requests { --kpi-from: #1d4ed8; --kpi-to: #0ea5e9; --kpi-glow: rgba(0, 0, 0, .45); }
html[data-theme="dark"] .analytics-kpi-card.tone-custody  { --kpi-from: #5b21b6; --kpi-to: #4f46e5; --kpi-glow: rgba(0, 0, 0, .45); }
html[data-theme="dark"] .analytics-kpi-card.tone-overdue  { --kpi-from: #9a3412; --kpi-to: #d97706; --kpi-glow: rgba(0, 0, 0, .45); }
html[data-theme="dark"] .analytics-kpi-card.tone-stock    { --kpi-from: #b91c1c; --kpi-to: #e11d48; --kpi-glow: rgba(0, 0, 0, .45); }

/* Reading cards ---------------------------------------------------------- */

.analytics-overview-main {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    align-items: stretch;
    gap: 13px;
}

.analytics-overview-rankings {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: 13px;
}

.analytics-card {
    display: flex;
    min-width: 0;
    flex-direction: column;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: var(--shadow-sm);
}

.analytics-card-head {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 15px;
    border-bottom: 1px solid var(--row-border);
}

.analytics-card-head > div { min-width: 0; flex: 1 1 auto; }

.analytics-card-mark {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    color: var(--interactive);
    background: var(--blue-50);
    border-radius: 8px;
}

.analytics-card-head h2 {
    margin: 0 0 1px;
    color: var(--heading);
    font-size: 14.5px;
    font-weight: 700;
    line-height: 1.25;
}

.analytics-card-head p {
    margin: 0;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.35;
}

.analytics-card-action {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    gap: 3px;
    color: var(--interactive);
    font-size: 11px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}

.analytics-card-action:hover { text-decoration: underline; }

.analytics-card-body { flex: 1 1 auto; padding: 13px 15px; }
.analytics-card-body.is-flush { padding: 0; }
.analytics-card-body.is-plot { display: flex; padding: 10px 12px 4px; }
.analytics-card-body > :last-child { margin-bottom: 0; }

.analytics-card-foot {
    padding: 8px 15px;
    border-top: 1px solid var(--row-border);
    color: var(--text-soft);
    font-size: 10.5px;
    line-height: 1.4;
}

/* A single-line reading, tinted so it separates from the plot above it. */
.analytics-insight-strip {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin: 0;
    padding: 9px 15px;
    border-top: 1px solid var(--row-border);
    background: var(--blue-50);
    color: var(--heading);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.4;
}

.analytics-insight-strip .ui-icon { flex-shrink: 0; margin-top: 1px; color: var(--interactive); }

/* Compact empty state: never a tall grey slab. */
.analytics-blank {
    display: flex;
    height: 100%;
    min-height: 72px;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 7px;
    margin: 0;
    padding: 6px 16px;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.45;
    text-align: center;
}

.analytics-blank-mark {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    color: var(--text-soft);
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: 50%;
}

/* Trend line ------------------------------------------------------------- */
/*
| Overview's time series. The SVG carries only the area and the stroke; the
| markers are positioned elements so they stay circular while the path is
| stretched to the box, and so each one can be a real link with a tooltip.
*/
.analytics-line { display: flex; width: 100%; min-width: 0; flex-direction: column; gap: 7px; }

.analytics-line-plot { position: relative; height: 150px; min-width: 0; }

.analytics-line-grid {
    position: absolute;
    inset: 7% 0 7% 0;
    background-image: repeating-linear-gradient(
        to bottom,
        var(--row-border) 0,
        var(--row-border) 1px,
        transparent 1px,
        transparent calc(50% - 0.5px)
    );
    opacity: .85;
    pointer-events: none;
}

.analytics-line-svg { position: absolute; inset: 0; width: 100%; height: 100%; overflow: visible; }

.analytics-line-area { fill: url(#analytics-line-gradient); fill-opacity: 1; }
.analytics-line-stroke { fill: none; stroke: #2563eb; stroke-width: 2.25; stroke-linecap: round; stroke-linejoin: round; }

.analytics-line-markers { position: absolute; inset: 0; }

.analytics-line-marker {
    position: absolute;
    display: grid;
    width: 26px;
    height: 26px;
    place-items: center;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    text-decoration: none;
}

.analytics-line-marker:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-line-dot {
    width: 8px;
    height: 8px;
    border: 2px solid #2563eb;
    border-radius: 50%;
    background: var(--surface-elevated);
    transition: transform var(--motion-fast) ease, background-color var(--motion-fast) ease;
}

.analytics-line-marker:hover .analytics-line-dot,
.analytics-line-marker:focus-visible .analytics-line-dot { transform: scale(1.35); background: #2563eb; }

/* The value rides above its point, so the plot needs no y-axis. */
.analytics-line-tip {
    position: absolute;
    bottom: 100%;
    left: 50%;
    transform: translateX(-50%);
    color: var(--heading);
    font-size: 10.5px;
    font-weight: 750;
    font-variant-numeric: tabular-nums;
    pointer-events: none;
}

.analytics-line-axis {
    display: flex;
    justify-content: space-between;
    gap: 4px;
    min-width: 0;
    padding-top: 6px;
    border-top: 1px solid var(--row-border);
}

.analytics-line-axis span {
    min-width: 0;
    flex: 1 1 0;
    color: var(--text-muted);
    font-size: 9.5px;
    line-height: 1.3;
    text-align: center;
    overflow-wrap: anywhere;
}

.analytics-line-axis span:first-child { text-align: left; }
.analytics-line-axis span:last-child { text-align: right; }

html[data-theme="dark"] .analytics-line-stroke { stroke: #60a5fa; }
html[data-theme="dark"] .analytics-line-dot { border-color: #60a5fa; }
html[data-theme="dark"] .analytics-line-marker:hover .analytics-line-dot { background: #60a5fa; }

/*
| A single bucket has no trend to plot. Rather than one bar adrift in a
| full-height chart, the period is stated as a figure: the count, a short bar
| for weight, and the bucket label. Same data, same drill-down target, a third
| of the height.
*/
.analytics-trend-single { display: grid; place-items: center; padding: 16px 15px; }

.analytics-trend-single-figure {
    display: grid;
    justify-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 10px;
    color: inherit;
    text-decoration: none;
    transition: background-color var(--motion-fast) ease;
}

.analytics-trend-single-figure:hover { background: var(--surface-hover); }
.analytics-trend-single-figure:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-trend-single-value { color: var(--text-muted); font-size: 12px; font-weight: 650; }

.analytics-trend-single-value strong {
    margin-right: 5px;
    color: var(--heading);
    font-size: 34px;
    font-weight: 780;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.analytics-trend-single-bar {
    display: block;
    width: 168px;
    max-width: 100%;
    height: 10px;
    overflow: hidden;
    background: var(--surface-muted);
    border-radius: 999px;
}

.analytics-trend-single-bar > span {
    display: block;
    width: 100%;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
}

.analytics-trend-single-label { color: var(--text-muted); font-size: 11px; text-align: center; }

/* Priority insights ------------------------------------------------------ */

.analytics-priority { display: flex; height: 100%; flex-direction: column; justify-content: flex-start; margin: 0; padding: 0; list-style: none; }

.analytics-priority-row {
    --priority-tone: var(--neutral);
    --priority-tone-bg: var(--neutral-bg);

    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    justify-content: center;
    min-height: 46px;
    max-height: 58px;
    border-bottom: 1px solid var(--row-border);
}

.analytics-priority-row:last-child { border-bottom: 0; }

.analytics-priority-row > a,
.analytics-priority-row > div {
    display: grid;
    grid-template-columns: 26px minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    padding: 7px 15px;
    color: inherit;
    text-decoration: none;
}

.analytics-priority-row > a { transition: background-color var(--motion-fast) ease; }
.analytics-priority-row > a:hover { background: var(--surface-hover); }
.analytics-priority-row > a:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-priority-icon {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    color: var(--priority-tone);
    background: var(--priority-tone-bg);
    border-radius: 8px;
}

.analytics-priority-body { display: grid; gap: 1px; min-width: 0; }

.analytics-priority-title {
    color: var(--text-muted);
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.analytics-priority-text {
    color: var(--heading);
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}

.analytics-priority-arrow { color: var(--text-soft); }
.analytics-priority-row > a:hover .analytics-priority-arrow { color: var(--interactive); }

.analytics-priority-row.tone-urgent { --priority-tone: var(--danger); --priority-tone-bg: var(--danger-bg); }
.analytics-priority-row.tone-urgent .analytics-priority-text { font-weight: 650; }
.analytics-priority-row.tone-rank { --priority-tone: #a16207; --priority-tone-bg: #fdf4e0; }
.analytics-priority-row.tone-item { --priority-tone: var(--success); --priority-tone-bg: var(--success-bg); }
.analytics-priority-row.tone-warning { --priority-tone: var(--warning); --priority-tone-bg: var(--warning-bg); }
.analytics-priority-row.tone-compliance { --priority-tone: var(--info); --priority-tone-bg: var(--info-bg); }
.analytics-priority-row.tone-neutral { --priority-tone: var(--neutral); --priority-tone-bg: var(--neutral-bg); }

html[data-theme="dark"] .analytics-priority-row.tone-rank { --priority-tone: #f0c25c; --priority-tone-bg: #2e2411; }

/* Ranked lists ----------------------------------------------------------- */

.analytics-rank { display: grid; align-content: start; gap: 2px; margin: 0; padding: 0; list-style: none; }

.analytics-rank-row {
    display: grid;
    grid-template-columns: 20px minmax(0, 1fr) auto;
    align-items: center;
    gap: 10px;
    padding: 6px 7px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
    transition: background-color var(--motion-fast) ease;
}

.analytics-rank-row:hover { background: var(--surface-hover); }
.analytics-rank-row:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-rank-no {
    display: grid;
    place-items: center;
    width: 20px;
    height: 20px;
    color: var(--interactive);
    background: var(--blue-50);
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.analytics-rank-main { display: grid; gap: 5px; min-width: 0; }

.analytics-rank-name {
    color: var(--heading);
    font-size: 12px;
    font-weight: 650;
    line-height: 1.25;
    overflow-wrap: anywhere;
}

.analytics-rank-name small {
    display: block;
    margin-top: 1px;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 500;
}

.analytics-rank-track {
    height: 6px;
    overflow: hidden;
    background: var(--surface-muted);
    border-radius: 999px;
}

.analytics-rank-fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
}

.analytics-rank-value {
    color: var(--heading);
    font-size: 13px;
    font-weight: 750;
    font-variant-numeric: tabular-nums;
    text-align: right;
    white-space: nowrap;
}

.analytics-rank-value small { display: block; color: var(--text-muted); font-size: 9.5px; font-weight: 500; }

/*
| Ranked list with no bar.
|
| Used when a comparison cannot be drawn honestly - every shown item tied on
| request count, or there is only one item. The track is absent rather than
| full width, so the row keeps the same three columns and simply sits closer
| together without it.
*/
.analytics-rank-flat .analytics-rank-row { padding-top: 8px; padding-bottom: 8px; }
.analytics-rank-flat .analytics-rank-main { gap: 0; }

/*
| Most Requested, ranked mode: the name sits beside its bar instead of above
| it, so the bars line up as one column and can be read against each other -
| which is the only reason to draw them at all. The split is proportional
| rather than a fixed name column, because this card sits in a half-width
| pair on desktop and goes full width below 900px.
|
| Scoped to this list: the other ranked lists on the tab keep the stacked form.
*/
.analytics-rank-split .analytics-rank-main {
    grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr);
    align-items: center;
    gap: 12px;
}

.analytics-rank-split .analytics-rank-row { padding-top: 9px; padding-bottom: 9px; }
.analytics-rank-split .analytics-rank-track { height: 16px; }

/* Too narrow to carry a name and a bar side by side: stack them again. */
@media (max-width: 560px) {
    .analytics-rank-split .analytics-rank-main {
        grid-template-columns: minmax(0, 1fr);
        align-items: stretch;
        gap: 6px;
    }

    .analytics-rank-split .analytics-rank-track { height: 10px; }
}

/* Period snapshot -------------------------------------------------------- */
/*
| A status strip, not a table: no header row, no cell borders, only a hairline
| between segments.
*/
.analytics-snapshot { flex-direction: row; align-items: stretch; flex-wrap: wrap; }

.analytics-snapshot-head {
    display: flex;
    flex: 0 0 auto;
    flex-direction: column;
    justify-content: center;
    gap: 2px;
    width: 210px;
    padding: 12px 15px;
    border-right: 1px solid var(--row-border);
}

.analytics-snapshot-head h2 { margin: 0; color: var(--heading); font-size: 14px; font-weight: 700; line-height: 1.25; }
.analytics-snapshot-head p { margin: 0; color: var(--text-muted); font-size: 10.5px; }

.analytics-snapshot-strip {
    display: grid;
    flex: 1 1 420px;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    min-width: 0;
}

.analytics-snapshot-tile {
    --snap-tone: var(--neutral);
    --snap-tone-bg: var(--neutral-bg);

    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
    padding: 12px 14px;
    border-left: 1px solid var(--row-border);
}

.analytics-snapshot-tile:first-child { border-left: 0; }

.analytics-snapshot-icon {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    color: var(--snap-tone);
    background: var(--snap-tone-bg);
    border-radius: 8px;
}

.analytics-snapshot-body { display: grid; gap: 0; min-width: 0; }

.analytics-snapshot-label {
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 700;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.analytics-snapshot-value {
    color: var(--heading);
    font-size: 18px;
    font-weight: 780;
    line-height: 1.15;
    font-variant-numeric: tabular-nums;
}

.analytics-snapshot-tile.tone-info { --snap-tone: var(--info); --snap-tone-bg: var(--info-bg); }
.analytics-snapshot-tile.tone-good { --snap-tone: var(--success); --snap-tone-bg: var(--success-bg); }
.analytics-snapshot-tile.tone-warn { --snap-tone: var(--warning); --snap-tone-bg: var(--warning-bg); }
.analytics-snapshot-tile.tone-risk { --snap-tone: var(--danger); --snap-tone-bg: var(--danger-bg); }

/* Responsive ------------------------------------------------------------- */

@media (max-width: 1180px) {
    .analytics-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-overview-main { grid-template-columns: minmax(0, 1fr); }
    .analytics-page .analytics-tab { flex: 0 0 auto; }
    .analytics-page .analytics-tabs { overflow-x: auto; }
}

@media (max-width: 980px) {
    .analytics-snapshot-head { width: 100%; border-right: 0; border-bottom: 1px solid var(--row-border); }
    .analytics-snapshot-strip { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .analytics-snapshot-tile:nth-child(4) { border-left: 0; }
}

/*
| The original filter panel drops to two columns at 1050px. As three separate
| compact cards they still fit comfortably, and two columns left the third card
| stranded beside a gap, so the three-up holds down to the tablet breakpoint.
*/
@media (min-width: 821px) {
    .analytics-page .analytics-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

@media (max-width: 820px) {
    .analytics-page .analytics-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-overview-rankings { grid-template-columns: minmax(0, 1fr); }
    .analytics-snapshot-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-snapshot-tile:nth-child(odd) { border-left: 0; }
}

@media (max-width: 620px) {
    .analytics-kpis { grid-template-columns: minmax(0, 1fr); }
    .analytics-kpi-card { min-height: 0; }
    .analytics-page .analytics-filters { grid-template-columns: minmax(0, 1fr); }
    .analytics-snapshot-strip { grid-template-columns: minmax(0, 1fr); }
    .analytics-snapshot-tile { border-left: 0; border-top: 1px solid var(--row-border); }
    .analytics-snapshot-tile:first-child { border-top: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .analytics-kpi-card,
    .analytics-kpi-card-arrow,
    .analytics-rank-row,
    .analytics-priority-row > a { transition: none; }
    .analytics-kpi-card:hover,
    .analytics-kpi-card:focus-visible { transform: none; }
}

/* ========================================================================
   Demand & Utilization.

   The tab reuses Overview's card, KPI and ranking components; only what is
   specific to this tab is defined here - two extra KPI tones, the division
   donut, the quiet-items list and the peak plot.
   ======================================================================== */

/* KPI tones -------------------------------------------------------------- */
/* Expressed demand sits beside filed demand, so it takes the adjacent indigo
   rather than a second blue that would read as the same measure. */
.analytics-kpi-card.tone-quantity { --kpi-from: #6d28d9; --kpi-to: #8b5cf6; --kpi-glow: rgba(109, 40, 217, .3); }
.analytics-kpi-card.tone-released { --kpi-from: #0f766e; --kpi-to: #10b981; --kpi-glow: rgba(15, 118, 110, .28); }
.analytics-kpi-card.tone-units    { --kpi-from: #b45309; --kpi-to: #f59e0b; --kpi-glow: rgba(180, 83, 9, .28); }

html[data-theme="dark"] .analytics-kpi-card.tone-quantity { --kpi-from: #5b21b6; --kpi-to: #7c3aed; --kpi-glow: rgba(0, 0, 0, .45); }
html[data-theme="dark"] .analytics-kpi-card.tone-released { --kpi-from: #115e59; --kpi-to: #059669; --kpi-glow: rgba(0, 0, 0, .45); }
html[data-theme="dark"] .analytics-kpi-card.tone-units    { --kpi-from: #92400e; --kpi-to: #d97706; --kpi-glow: rgba(0, 0, 0, .45); }

/* A card with nothing to open should not lift or shift under the pointer. */
.analytics-kpi-card.is-static { cursor: default; }
.analytics-kpi-card.is-static:hover { transform: none; box-shadow: 0 1px 2px var(--kpi-glow); }

/* Row grids -------------------------------------------------------------- */
/* Same proportions Overview uses: a plot needs width, a legend does not. */
.analytics-demand-main {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    align-items: stretch;
    gap: 13px;
    margin-bottom: 13px;
}

.analytics-demand-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: 13px;
    margin-bottom: 13px;
}

/* Division donut --------------------------------------------------------- */
.analytics-donut-body {
    display: grid;
    grid-template-columns: 152px minmax(0, 1fr);
    align-items: center;
    gap: 16px;
}

.analytics-donut {
    position: relative;
    display: grid;
    place-items: center;
    width: 152px;
    height: 152px;
}

.analytics-donut svg {
    width: 100%;
    height: 100%;
    /* Slices are laid out from 12 o'clock, which is where a reader starts. */
    transform: rotate(-90deg);
}

.analytics-donut circle {
    fill: none;
    stroke-width: 5.2;
}

.analytics-donut-rail { stroke: var(--surface-subtle); }

/* One key per division, matching the bar colours used on the other tabs. */
.analytics-donut-arc.is-academic       { stroke: #1769e0; }
.analytics-donut-arc.is-administration { stroke: #0e7c66; }
.analytics-donut-arc.is-research       { stroke: #7a4bc4; }
.analytics-donut-arc.is-unspecified    { stroke: var(--text-soft); }

.analytics-donut-centre {
    position: absolute;
    display: grid;
    justify-items: center;
    gap: 1px;
    text-align: center;
}

.analytics-donut-centre strong {
    color: var(--heading);
    font-size: 25px;
    font-weight: 800;
    line-height: 1;
}

.analytics-donut-centre small {
    max-width: 84px;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 700;
    line-height: 1.25;
}

.analytics-donut-legend { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }

.analytics-donut-legend a,
.analytics-donut-legend div {
    display: grid;
    grid-template-columns: 10px minmax(0, 1fr) auto;
    align-items: center;
    gap: 9px;
    padding: 6px 8px;
    border-radius: 7px;
    color: inherit;
    text-decoration: none;
}

.analytics-donut-legend a:hover { background: var(--surface-hover); }
.analytics-donut-legend a:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-donut-key { width: 10px; height: 10px; border-radius: 50%; background: var(--text-soft); }
.analytics-donut-key.is-academic       { background: #1769e0; }
.analytics-donut-key.is-administration { background: #0e7c66; }
.analytics-donut-key.is-research       { background: #7a4bc4; }

.analytics-donut-name {
    min-width: 0;
    color: var(--text-primary);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.35;
}

.analytics-donut-figure {
    color: var(--heading);
    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.analytics-donut-figure small { color: var(--text-muted); font-weight: 700; }

/* Ranking additions ------------------------------------------------------ */
/*
 | Division affiliation is metadata on the unit, not a heading above it.
 |
 | Scoped under the name because .analytics-rank-name small already blocks its
 | children full width, which stretched the pill across the whole row.
 */
.analytics-rank-name small.analytics-rank-tag {
    display: inline-block;
    width: max-content;
    max-width: 100%;
    margin-top: 3px;
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--surface-subtle);
    color: var(--text-muted);
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .03em;
}

.analytics-rank-name small.analytics-rank-tag.is-academic       { color: #1769e0; background: rgba(23, 105, 224, .1); }
.analytics-rank-name small.analytics-rank-tag.is-administration { color: #0e7c66; background: rgba(14, 124, 102, .1); }
.analytics-rank-name small.analytics-rank-tag.is-research       { color: #7a4bc4; background: rgba(122, 75, 196, .1); }

/* Actual release is a different measure from expressed demand, and reads in
   the same green the Released Quantity card uses. */
.analytics-rank-fill.is-released { background: #0e7c66; }

/* Quiet items ------------------------------------------------------------ */
.analytics-quiet { display: grid; gap: 1px; margin: 0; padding: 0; list-style: none; }

.analytics-quiet a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 9px;
    border-radius: 7px;
    color: inherit;
    text-decoration: none;
}

.analytics-quiet a:hover { background: var(--surface-hover); }
.analytics-quiet a:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-quiet-name {
    min-width: 0;
    color: var(--interactive);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.35;
}

.analytics-quiet-state {
    flex: 0 0 auto;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
    white-space: nowrap;
}

/* Peak plot -------------------------------------------------------------- */
.analytics-peak { display: grid; gap: 7px; margin: 0; padding: 0; list-style: none; }

.analytics-peak li {
    display: grid;
    grid-template-columns: 108px minmax(0, 1fr) 82px;
    align-items: center;
    gap: 11px;
}

.analytics-peak-label { color: var(--text-primary); font-size: 11.5px; font-weight: 600; }

.analytics-peak-track {
    height: 9px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-subtle);
}

.analytics-peak-fill { display: block; height: 100%; min-width: 2px; border-radius: 999px; background: #1769e0; }
.analytics-peak li.is-peak .analytics-peak-fill { background: #0e7c66; }

.analytics-peak-value {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 6px;
    color: var(--heading);
    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.analytics-peak-tag {
    padding: 1px 6px;
    border-radius: 999px;
    color: #0e7c66;
    background: rgba(14, 124, 102, .12);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}

/* The insufficient-data panel carries a lead line, so it aligns to the top. */
.analytics-blank strong { display: block; margin-bottom: 2px; color: var(--heading); }

/* Responsive ------------------------------------------------------------- */
@media (max-width: 1180px) {
    .analytics-demand-main { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 900px) {
    .analytics-demand-pair { grid-template-columns: minmax(0, 1fr); }
    .analytics-peak li { grid-template-columns: 92px minmax(0, 1fr) 74px; }
}

@media (max-width: 560px) {
    /* The legend reads better under the ring than squeezed beside it. */
    .analytics-donut-body { grid-template-columns: minmax(0, 1fr); justify-items: center; }
    .analytics-donut-legend { width: 100%; }
}

/* ========================================================================
   Inventory Health.

   Reuses the card, KPI and ranking components the other Analytics tabs use;
   only what is specific to inventory is defined here - two more KPI tones,
   the per-item availability bars, the composition bar, the watchlist, the
   secondary state tiles and the coverage rows.

   One colour key runs through the whole tab: green is available, blue is on
   custody, amber is laundry, red is held by an incident. The availability
   bars, the composition bar and the state tiles all obey it, so a segment
   means the same thing wherever it appears.
   ======================================================================== */

/* KPI tones -------------------------------------------------------------- */
.analytics-kpi-card.tone-available { --kpi-from: #047857; --kpi-to: #10b981; --kpi-glow: rgba(4, 120, 87, .28); }
.analytics-kpi-card.tone-attention { --kpi-from: #b91c1c; --kpi-to: #f97316; --kpi-glow: rgba(185, 28, 28, .28); }

html[data-theme="dark"] .analytics-kpi-card.tone-available { --kpi-from: #065f46; --kpi-to: #059669; --kpi-glow: rgba(0, 0, 0, .45); }
html[data-theme="dark"] .analytics-kpi-card.tone-attention { --kpi-from: #991b1b; --kpi-to: #ea580c; --kpi-glow: rgba(0, 0, 0, .45); }

/* Row grids -------------------------------------------------------------- */
.analytics-inventory-main {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    align-items: stretch;
    gap: 13px;
    margin-bottom: 13px;
}

.analytics-inventory-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: 13px;
    margin-bottom: 13px;
}

/* Shared state colours --------------------------------------------------- */
.analytics-dist-seg.is-available, .analytics-dist-key.is-available { background: #0e7c66; }
.analytics-dist-seg.is-custody,   .analytics-dist-key.is-custody   { background: #1769e0; }
.analytics-dist-seg.is-laundry,   .analytics-dist-key.is-laundry   { background: #d08a16; }
.analytics-dist-seg.is-incident,  .analytics-dist-key.is-incident  { background: #c4493d; }

/* Per-item availability -------------------------------------------------- */
.analytics-avail { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }

.analytics-avail a {
    display: grid;
    gap: 6px;
    padding: 8px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}

.analytics-avail a:hover { background: var(--surface-hover); }
.analytics-avail a:focus-visible { outline: none; box-shadow: var(--focus-ring); }

/* Long equipment names wrap rather than truncate: the name is the row's key. */
.analytics-avail-name {
    min-width: 0;
    color: var(--text-primary);
    font-size: 11.5px;
    font-weight: 600;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

/* Bar and its readings share one line; the bar takes what the numbers leave. */
.analytics-avail-meter {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: 12px;
}

.analytics-avail-figure {
    display: flex;
    align-items: baseline;
    gap: 8px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.analytics-avail-figure strong { color: var(--heading); font-size: 12px; font-weight: 800; }
.analytics-avail-figure strong.is-low { color: #c4493d; }

.analytics-avail-tag {
    flex: 0 0 auto;
    padding: 1px 6px;
    border-radius: 999px;
    color: #0e7c66;
    background: rgba(14, 124, 102, .12);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .03em;
    text-transform: uppercase;
}

.analytics-avail-tag.is-low { color: #b3261e; background: rgba(196, 73, 61, .14); }

/*
| One reading per bar: the fill is the usable share, the rail behind it is the
| remainder. The remainder stays neutral on purpose - a second saturated colour
| there reads as a competing measure rather than as what is simply not usable.
*/
.analytics-avail-bar {
    display: block;
    height: 8px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-muted);
}

.analytics-avail-fill {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #0e7c66;
}

/* At or below the service's threshold, and that includes nothing available. */
.analytics-avail-fill.is-low { background: #c4493d; }

/* Composition ------------------------------------------------------------ */
.analytics-dist-total {
    display: flex;
    align-items: baseline;
    gap: 8px;
    margin: 0 0 10px;
}

.analytics-dist-total strong { color: var(--heading); font-size: 24px; font-weight: 800; line-height: 1; }
.analytics-dist-total span { color: var(--text-muted); font-size: 11px; line-height: 1.4; }

.analytics-dist-bar {
    display: flex;
    height: 11px;
    margin-bottom: 12px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-subtle);
}

.analytics-dist-seg { display: block; height: 100%; min-width: 2px; }

.analytics-dist-legend { display: grid; gap: 1px; margin: 0; padding: 0; list-style: none; }

.analytics-dist-legend li {
    display: grid;
    grid-template-columns: 10px minmax(0, 1fr) auto;
    align-items: center;
    gap: 9px;
    padding: 4px 2px;
}

.analytics-dist-key { width: 10px; height: 10px; border-radius: 50%; background: var(--text-soft); }
.analytics-dist-name { min-width: 0; color: var(--text-primary); font-size: 11.5px; font-weight: 600; }

.analytics-dist-figure {
    color: var(--heading);
    font-size: 12px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.analytics-dist-figure small { color: var(--text-muted); font-weight: 700; }

/* What the bar deliberately leaves out, and why. */
.analytics-dist-aside {
    display: grid;
    gap: 7px;
    margin: 12px 0 0;
    padding-top: 10px;
    border-top: 1px solid var(--border);
}

.analytics-dist-aside div { display: grid; gap: 1px; }
.analytics-dist-aside dt { color: var(--text-muted); font-size: 9.5px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
.analytics-dist-aside dd { margin: 0; color: var(--text-secondary); font-size: 11px; line-height: 1.5; }

/* Low availability watchlist --------------------------------------------- */
.analytics-count-pill {
    flex: 0 0 auto;
    min-width: 22px;
    padding: 2px 8px;
    border-radius: 999px;
    color: #b3461c;
    background: rgba(208, 138, 22, .14);
    font-size: 11.5px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

.analytics-watch { display: grid; gap: 1px; margin: 0; padding: 0; list-style: none; }

.analytics-watch a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 9px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}

.analytics-watch a:hover { background: var(--surface-hover); }
.analytics-watch a:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-watch-main { min-width: 0; display: grid; gap: 1px; }
.analytics-watch-name { color: var(--interactive); font-size: 11.5px; font-weight: 700; }
.analytics-watch-sub { color: var(--text-muted); font-size: 10.5px; }

.analytics-watch-figure {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 8px;
}

.analytics-watch-figure strong { color: #c4493d; font-size: 13px; font-weight: 800; font-variant-numeric: tabular-nums; }

/* Secondary state tiles -------------------------------------------------- */
.analytics-tiles { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }

.analytics-tile {
    display: grid;
    gap: 2px;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-subtle);
}

.analytics-tile-icon {
    display: inline-grid;
    width: 26px;
    height: 26px;
    place-items: center;
    margin-bottom: 3px;
    border-radius: 8px;
    color: var(--text-muted);
    background: var(--surface);
}

.analytics-tile-label { color: var(--text-muted); font-size: 9.5px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
.analytics-tile-value { color: var(--heading); font-size: 20px; font-weight: 800; line-height: 1.1; }
.analytics-tile-note { color: var(--text-muted); font-size: 10px; line-height: 1.4; }

/* A tile only takes a tone once it actually holds something. */
.analytics-tile.is-flagged.is-laundry { border-color: rgba(208, 138, 22, .35); background: rgba(208, 138, 22, .08); }
.analytics-tile.is-flagged.is-laundry .analytics-tile-icon { color: #b3461c; }
.analytics-tile.is-flagged.is-maintenance,
.analytics-tile.is-flagged.is-incident { border-color: rgba(196, 73, 61, .35); background: rgba(196, 73, 61, .08); }
.analytics-tile.is-flagged.is-maintenance .analytics-tile-icon,
.analytics-tile.is-flagged.is-incident .analytics-tile-icon { color: #c4493d; }

/* Coverage rows ---------------------------------------------------------- */
.analytics-coverage { display: grid; gap: 1px; margin: 0; padding: 0; list-style: none; }

.analytics-coverage a {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 9px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
}

.analytics-coverage a:hover { background: var(--surface-hover); }
.analytics-coverage a:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-coverage-main { min-width: 0; display: grid; gap: 1px; }
.analytics-coverage-name { color: var(--interactive); font-size: 11.5px; font-weight: 700; }
.analytics-coverage-sub { color: var(--text-muted); font-size: 10.5px; font-variant-numeric: tabular-nums; }

.analytics-coverage-figure {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 8px;
}

.analytics-coverage-figure strong { color: var(--heading); font-size: 14px; font-weight: 800; font-variant-numeric: tabular-nums; }
.analytics-coverage-figure strong small { color: var(--text-muted); font-size: 10px; font-weight: 700; }

/* A cleared watchlist is good news, and reads as such. */
.analytics-blank.is-positive { border-color: rgba(14, 124, 102, .3); background: rgba(14, 124, 102, .06); }
.analytics-blank.is-positive .analytics-blank-mark { color: #0e7c66; }

/* Responsive ------------------------------------------------------------- */
@media (max-width: 1180px) {
    .analytics-inventory-main { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 900px) {
    .analytics-inventory-pair { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 560px) {
    .analytics-tiles { grid-template-columns: minmax(0, 1fr); }
    /* Readings above the bar, each on its own row, nothing squeezed. */
    .analytics-avail-meter { grid-template-columns: minmax(0, 1fr); gap: 6px; }
    .analytics-avail-meter .analytics-avail-figure { order: -1; }
    .analytics-avail-figure { flex-wrap: wrap; white-space: normal; }
}

/* ======================================================================== */
/* Borrowing & Return Performance                                           */
/*                                                                          */
/* Reuses the Analytics card, KPI and ranking system already established in  */
/* the other tabs; only the pieces this tab introduces are defined here.     */
/* ======================================================================== */

/* Completed-on-time gets the one gradient the other tabs did not need. */
.analytics-kpi-card.tone-ontime { --kpi-from: #047857; --kpi-to: #34d399; --kpi-glow: rgba(4, 120, 87, .28); }
html[data-theme="dark"] .analytics-kpi-card.tone-ontime { --kpi-from: #065f46; --kpi-to: #10b981; --kpi-glow: rgba(0, 0, 0, .45); }

/* A card with no detail behind it must not offer hover or a chevron. */
.analytics-kpi-card.is-static { cursor: default; }
.analytics-kpi-card.is-static:hover { transform: none; box-shadow: 0 4px 14px var(--kpi-glow); }

/* Two-series line ------------------------------------------------------- */

.analytics-line--dual { gap: 5px; }

.analytics-line-legend { display: flex; gap: 14px; padding: 0 2px; }

.analytics-line-key {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
}

.analytics-line-key::before {
    content: '';
    width: 14px;
    height: 3px;
    border-radius: 999px;
    background: currentColor;
}

.analytics-line-key.is-ontime { color: var(--success); }
.analytics-line-key.is-late { color: var(--warning); }

.analytics-line-stroke.is-ontime { stroke: #047857; }
.analytics-line-stroke.is-late { stroke: #c2410c; }

.analytics-line-marker.is-static { cursor: default; }
.analytics-line-marker.is-ontime .analytics-line-dot { border-color: #047857; }
.analytics-line-marker.is-late .analytics-line-dot { border-color: #c2410c; }

html[data-theme="dark"] .analytics-line-stroke.is-ontime { stroke: #34d399; }
html[data-theme="dark"] .analytics-line-stroke.is-late { stroke: #f59e0b; }
html[data-theme="dark"] .analytics-line-marker.is-ontime .analytics-line-dot { border-color: #34d399; }
html[data-theme="dark"] .analytics-line-marker.is-late .analytics-line-dot { border-color: #f59e0b; }

.analytics-return-split { display: flex; gap: 12px; font-size: 11.5px; font-weight: 700; }
.analytics-return-split .is-ontime { color: var(--success); }
.analytics-return-split .is-late { color: var(--warning); }

/* Return outcome donut --------------------------------------------------- */

.analytics-donut-body { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }

.analytics-donut { position: relative; width: 128px; height: 128px; flex: 0 0 auto; }
.analytics-donut svg { width: 100%; height: 100%; transform: rotate(-90deg); }

.analytics-donut-track { fill: none; stroke: var(--surface-muted); stroke-width: 13; }
.analytics-donut-arc { fill: none; stroke-width: 13; stroke-linecap: butt; }
.analytics-donut-arc.is-ontime { stroke: #047857; }
.analytics-donut-arc.is-late { stroke: #c2410c; }

html[data-theme="dark"] .analytics-donut-arc.is-ontime { stroke: #34d399; }
html[data-theme="dark"] .analytics-donut-arc.is-late { stroke: #f59e0b; }

.analytics-donut-centre {
    position: absolute;
    inset: 0;
    display: grid;
    place-content: center;
    gap: 1px;
    text-align: center;
}

.analytics-donut-centre strong { color: var(--heading); font-size: 26px; font-weight: 780; line-height: 1; font-variant-numeric: tabular-nums; }
.analytics-donut-centre small { color: var(--text-muted); font-size: 9.5px; font-weight: 700; }

.analytics-donut-legend { display: grid; gap: 10px; min-width: 0; flex: 1 1 130px; margin: 0; }
.analytics-donut-legend > div { display: grid; gap: 1px; padding-left: 11px; border-left: 3px solid currentColor; }
.analytics-donut-legend > div.is-ontime { color: #047857; }
.analytics-donut-legend > div.is-late { color: #c2410c; }
.analytics-donut-legend dt { color: var(--text-muted); font-size: 10.5px; font-weight: 700; }
.analytics-donut-legend dd { margin: 0; color: var(--heading); font-size: 15px; font-weight: 750; font-variant-numeric: tabular-nums; }
.analytics-donut-legend dd span { color: var(--text-muted); font-size: 11.5px; font-weight: 600; }

html[data-theme="dark"] .analytics-donut-legend > div.is-ontime { color: #34d399; }
html[data-theme="dark"] .analytics-donut-legend > div.is-late { color: #f59e0b; }

/* Lifecycle strip -------------------------------------------------------- */

.analytics-lifecycle-strip {
    display: flex;
    align-items: stretch;
    gap: 2px;
    padding: 13px 15px 15px;
}

.analytics-lifecycle-stage {
    display: grid;
    align-content: start;
    gap: 2px;
    min-width: 0;
    flex: 1 1 0;
    padding: 10px 12px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
}

.analytics-lifecycle-label { color: var(--text-muted); font-size: 10px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; overflow-wrap: anywhere; }
.analytics-lifecycle-value { color: var(--heading); font-size: 22px; font-weight: 780; line-height: 1.1; font-variant-numeric: tabular-nums; }
.analytics-lifecycle-note { color: var(--text-muted); font-size: 10px; line-height: 1.35; overflow-wrap: anywhere; }

.analytics-lifecycle-bar {
    display: block;
    height: 4px;
    min-width: 0;
    margin-top: 7px;
    border-radius: 999px;
    background: linear-gradient(90deg, #2563eb 0%, #38bdf8 100%);
}

.analytics-lifecycle-link { display: grid; place-items: center; flex: 0 0 auto; color: var(--text-soft); }

/* Overdue follow-up list ------------------------------------------------- */

.analytics-followup { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }

.analytics-followup-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
    transition: background-color var(--motion-fast) ease;
}

.analytics-followup-row:hover { background: var(--surface-hover); }
.analytics-followup-row:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-followup-main { display: grid; gap: 1px; min-width: 0; }
.analytics-followup-name { color: var(--heading); font-size: 12.5px; font-weight: 650; overflow-wrap: anywhere; }
.analytics-followup-ref { color: var(--text-muted); font-size: 10.5px; overflow-wrap: anywhere; }

.analytics-followup-due { display: grid; gap: 1px; flex-shrink: 0; text-align: right; }
.analytics-followup-due span { color: var(--text-muted); font-size: 10.5px; }
.analytics-followup-due strong { color: var(--danger); font-size: 12px; font-weight: 750; white-space: nowrap; }

/* Condition bars reuse the ranking rows, minus the link affordance. */
.analytics-rank-row.is-static { cursor: default; }
.analytics-rank-row.is-static:hover { background: transparent; }
.analytics-rank-fill.is-good { background: linear-gradient(90deg, #047857 0%, #34d399 100%); }
.analytics-rank-fill.is-issue { background: linear-gradient(90deg, #c2410c 0%, #f59e0b 100%); }

.analytics-blank-mark.is-good { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }

/* Mini stat tiles -------------------------------------------------------- */

.analytics-ministats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }

.analytics-ministat {
    display: grid;
    align-content: center;
    gap: 3px;
    min-width: 0;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface);
}

.analytics-ministat span { color: var(--text-muted); font-size: 10px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; overflow-wrap: anywhere; }
.analytics-ministat strong { color: var(--heading); font-size: 19px; font-weight: 780; line-height: 1.15; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }

/* "Not measurable" is a statement, not a number, so it is not styled as one. */
.analytics-ministat.is-unmeasured strong { color: var(--text-muted); font-size: 12.5px; font-weight: 650; }

/* Issue breakdown -------------------------------------------------------- */

.analytics-issues { display: grid; align-content: start; gap: 0; margin: 0; padding: 0; list-style: none; }

.analytics-issues li {
    --issue-tone: var(--neutral);

    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 9px 2px;
    border-bottom: 1px solid var(--row-border);
}

.analytics-issues li:last-child { border-bottom: 0; }
.analytics-issues li.tone-risk { --issue-tone: var(--danger); }
.analytics-issues li.tone-warn { --issue-tone: var(--warning); }
.analytics-issues li.tone-good { --issue-tone: var(--success); }

.analytics-issues-label { display: grid; gap: 1px; min-width: 0; color: var(--heading); font-size: 12.5px; overflow-wrap: anywhere; }
.analytics-issues-label small { color: var(--text-muted); font-size: 10.5px; }
.analytics-issues li strong { flex-shrink: 0; color: var(--issue-tone); font-size: 17px; font-weight: 780; font-variant-numeric: tabular-nums; }

/* Responsive ------------------------------------------------------------- */

@media (max-width: 1180px) {
    .analytics-lifecycle-strip { flex-wrap: wrap; }
    .analytics-lifecycle-stage { flex: 1 1 40%; }
    .analytics-lifecycle-link { display: none; }
}

@media (max-width: 620px) {
    .analytics-lifecycle-stage { flex: 1 1 100%; }
    .analytics-ministats { grid-template-columns: minmax(0, 1fr); }
    .analytics-donut-body { justify-content: center; }
}

/* ========================================================================
   Forecast & Planning.

   Reuses the card, KPI, ranking and peak components the other Analytics tabs
   use. What is defined here is only what this tab needs: an outlook plot that
   draws observed and projected differently, a readiness checklist, coverage
   comparison bars, planning notes, and the methodology strip.

   One rule runs through it: a solid blue mark is something that happened, a
   dashed violet mark is something projected. A reader should never have to
   check a label to tell which is which.
   ======================================================================== */

/* Row grids -------------------------------------------------------------- */
.analytics-forecast-main {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr);
    align-items: stretch;
    gap: 13px;
    margin-bottom: 13px;
}

.analytics-forecast-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-items: stretch;
    gap: 13px;
    margin-bottom: 13px;
}

.analytics-forecast-equipment { margin-bottom: 13px; }

/* A KPI whose value is a state rather than a count. */
.analytics-kpi-card-value.is-text { font-size: 19px; line-height: 1.2; }

/* Outlook plot ----------------------------------------------------------- */
.analytics-outlook-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    margin: 0 0 10px;
    padding: 0;
    list-style: none;
}

.analytics-outlook-legend li {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
}

.analytics-outlook-key { width: 16px; height: 0; border-top: 2px solid var(--text-soft); }
.analytics-outlook-key.is-observed { border-top-color: #2563eb; }
.analytics-outlook-key.is-forecast { border-top-style: dashed; border-top-color: #7a4bc4; }
.analytics-outlook-key.is-scheduled { height: 9px; width: 9px; border: 0; border-radius: 50%; background: #0e7c66; }

.analytics-outlook { display: grid; gap: 6px; }
.analytics-outlook-plot { position: relative; height: 150px; }

.analytics-outlook-plot svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: visible;
}

/* The path is stretched to the box, so the stroke keeps a true width. */
.analytics-outlook-solid { fill: none; stroke: #2563eb; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.analytics-outlook-dashed { fill: none; stroke: #7a4bc4; stroke-width: 2; stroke-dasharray: 4 4; stroke-linecap: round; }

.analytics-outlook-markers { position: absolute; inset: 0; }

.analytics-outlook-marker {
    position: absolute;
    display: grid;
    justify-items: center;
    transform: translate(-50%, -50%);
}

.analytics-outlook-dot {
    width: 9px;
    height: 9px;
    border: 2px solid var(--surface);
    border-radius: 50%;
    background: #2563eb;
}

/* A projected point is hollow, so it never reads as a recorded one. */
.analytics-outlook-marker.is-forecast .analytics-outlook-dot {
    border-color: #7a4bc4;
    background: var(--surface);
}

.analytics-outlook-tip {
    position: absolute;
    bottom: 11px;
    color: var(--heading);
    font-size: 10px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.analytics-outlook-marker.is-forecast .analytics-outlook-tip { color: #7a4bc4; }

.analytics-outlook-axis {
    display: flex;
    justify-content: space-between;
    gap: 6px;
    padding-top: 6px;
    border-top: 1px solid var(--border);
}

.analytics-outlook-axis span {
    flex: 1 1 0;
    min-width: 0;
    color: var(--text-muted);
    font-size: 9.5px;
    line-height: 1.3;
    text-align: center;
}

.analytics-outlook-axis span:first-child { text-align: left; }
.analytics-outlook-axis span:last-child { text-align: right; }
.analytics-outlook-axis span.is-forecast { color: #7a4bc4; font-weight: 800; }

.analytics-outlook-note {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    margin: 12px 0 0;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--surface-subtle);
    color: var(--text-secondary);
    font-size: 11px;
    line-height: 1.5;
}

.analytics-outlook-note .ui-icon { flex: 0 0 auto; margin-top: 1px; color: var(--text-soft); }
.analytics-outlook-note strong { display: block; color: var(--heading); font-size: 11.5px; }

.analytics-outlook-note.is-scheduled { border-color: rgba(14, 124, 102, .3); background: rgba(14, 124, 102, .06); }
.analytics-outlook-note.is-scheduled .ui-icon { color: #0e7c66; }

/* Readiness checklist ---------------------------------------------------- */
.analytics-check { display: grid; gap: 3px; margin: 0; padding: 0; list-style: none; }

.analytics-check li {
    display: grid;
    grid-template-columns: 22px minmax(0, 1fr) auto;
    align-items: center;
    gap: 9px;
    padding: 8px 9px;
    border-radius: 8px;
    background: var(--surface-subtle);
}

.analytics-check-mark { display: inline-grid; place-items: center; }
.analytics-check li.is-met .analytics-check-mark { color: #0e7c66; }
.analytics-check li.is-unmet .analytics-check-mark { color: #d08a16; }
.analytics-check li.is-unmet { background: rgba(208, 138, 22, .08); }

.analytics-check-label { min-width: 0; color: var(--text-primary); font-size: 11.5px; font-weight: 600; }

.analytics-check-value {
    color: var(--heading);
    font-size: 11.5px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.analytics-check-state {
    display: grid;
    gap: 2px;
    margin: 11px 0 0;
    padding: 11px 12px;
    border: 1px solid rgba(208, 138, 22, .32);
    border-radius: 9px;
    background: rgba(208, 138, 22, .07);
}

.analytics-check-state strong { color: var(--heading); font-size: 12px; }
.analytics-check-state span { color: var(--text-secondary); font-size: 11px; line-height: 1.5; }
.analytics-check-state.is-ready { border-color: rgba(14, 124, 102, .32); background: rgba(14, 124, 102, .07); }

/* Equipment coverage comparison ------------------------------------------ */
.analytics-coverbars {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.analytics-coverbars a {
    display: grid;
    gap: 6px;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-subtle);
    color: inherit;
    text-decoration: none;
}

.analytics-coverbars a:hover { border-color: var(--border-strong); }
.analytics-coverbars a:focus-visible { outline: none; box-shadow: var(--focus-ring); }
.analytics-coverbars li.is-risk a { border-color: rgba(196, 73, 61, .32); background: rgba(196, 73, 61, .06); }

.analytics-coverbars-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

.analytics-coverbars-name {
    min-width: 0;
    overflow: hidden;
    color: var(--heading);
    font-size: 11.5px;
    font-weight: 700;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.analytics-coverbars-row {
    display: grid;
    grid-template-columns: 62px minmax(0, 1fr) 46px;
    align-items: center;
    gap: 8px;
}

.analytics-coverbars-key { color: var(--text-muted); font-size: 10px; font-weight: 700; }

.analytics-coverbars-track {
    height: 7px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface);
}

.analytics-coverbars-fill { display: block; height: 100%; min-width: 2px; border-radius: 999px; }
.analytics-coverbars-fill.is-demand { background: #7a4bc4; }
.analytics-coverbars-fill.is-available { background: #0e7c66; }

.analytics-coverbars-value {
    color: var(--heading);
    font-size: 11px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    text-align: right;
}

/* Planning notes --------------------------------------------------------- */
.analytics-notes { display: grid; margin: 0; padding: 0; list-style: none; }

.analytics-notes li {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 15px;
    border-bottom: 1px solid var(--border);
    color: var(--text-secondary);
    font-size: 11.5px;
    line-height: 1.55;
}

.analytics-notes li:last-child { border-bottom: 0; }

.analytics-notes-icon {
    display: inline-grid;
    width: 26px;
    height: 26px;
    flex: 0 0 auto;
    place-items: center;
    border-radius: 8px;
    color: var(--text-muted);
    background: var(--surface-subtle);
}

.analytics-notes li.tone-urgent .analytics-notes-icon { color: #c4493d; background: rgba(196, 73, 61, .1); }
.analytics-notes li.tone-steady .analytics-notes-icon { color: #0e7c66; background: rgba(14, 124, 102, .1); }
.analytics-notes li.tone-info .analytics-notes-icon { color: #1769e0; background: rgba(23, 105, 224, .1); }

/* Methodology strip ------------------------------------------------------ */
.analytics-method { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 9px; }

.analytics-method > div {
    display: grid;
    gap: 3px;
    padding: 11px 12px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-subtle);
}

.analytics-method span { color: var(--text-muted); font-size: 9.5px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
.analytics-method strong { color: var(--heading); font-size: 12.5px; font-weight: 800; }

.analytics-method-summary { margin: 0 0 8px; color: var(--text-secondary); font-size: 11px; line-height: 1.55; }
.analytics-method-list { display: grid; gap: 5px; margin: 0; padding-left: 17px; color: var(--text-secondary); font-size: 11px; line-height: 1.55; }

/* Collapsible detail ----------------------------------------------------- */
.analytics-fold { border-top: 1px solid var(--border); }

.analytics-fold > summary {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 10px 15px;
    color: var(--interactive);
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    list-style: none;
}

.analytics-fold > summary::-webkit-details-marker { display: none; }

.analytics-fold > summary::after {
    content: '';
    width: 6px;
    height: 6px;
    border-right: 1.6px solid currentColor;
    border-bottom: 1.6px solid currentColor;
    transform: translateY(-2px) rotate(45deg);
    transition: transform var(--motion) ease;
}

.analytics-fold[open] > summary::after { transform: translateY(1px) rotate(-135deg); }
.analytics-fold > summary:hover { text-decoration: underline; }
.analytics-fold > summary:focus-visible { outline: none; box-shadow: var(--focus-ring); }
.analytics-fold-body { padding: 0 15px 14px; }

.analytics-mini-table { width: 100%; border-collapse: collapse; }
.analytics-mini-table th {
    padding: 6px 8px;
    border-bottom: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .04em;
    text-align: left;
    text-transform: uppercase;
}
.analytics-mini-table td { padding: 7px 8px; border-bottom: 1px solid var(--row-border); font-size: 11.5px; }
.analytics-mini-table tr:last-child td { border-bottom: 0; }
.analytics-mini-table .numeric { text-align: right; font-variant-numeric: tabular-nums; }

/* Responsive ------------------------------------------------------------- */
@media (max-width: 1180px) {
    .analytics-forecast-main { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 900px) {
    .analytics-forecast-pair { grid-template-columns: minmax(0, 1fr); }
    .analytics-coverbars { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 560px) {
    .analytics-method { grid-template-columns: minmax(0, 1fr); }
    .analytics-outlook-axis span { font-size: 9px; }
}

@media (prefers-reduced-motion: reduce) {
    .analytics-fold > summary::after { transition: none; }
}

/* ========================================================================
   Analytics detail drawer.

   The single source of truth for how the drawer is sized and spaced. It is a
   quick inspection of one figure, not a second dashboard, so it takes about a
   third of a desktop screen and leaves the Analytics page readable behind it.

   Earlier rules above set 660px, then 820px. Both are superseded here.
   ======================================================================== */

/*
| Width.
|
| 40vw is the intent, 520px the cap: past about 1300px a wider drawer only adds
| line length, it does not add information. The tablet and phone steps widen it
| because there the page behind has nothing left to show anyway.
*/
.analytics-detail {
    width: min(520px, 40vw);
    max-width: 560px;
}

/* Tablet: the page behind is already narrow, so the drawer may take more. */
@media (max-width: 1180px) {
    .analytics-detail { width: 60vw; max-width: none; }
}

@media (max-width: 900px) {
    .analytics-detail { width: 72vw; max-width: none; }
}

/* Phone: full width is the only sensible reading width. */
@media (max-width: 720px) {
    .analytics-detail { width: 100%; max-width: none; border-left: 0; }
}

/*
| Backdrop.
|
| Dimmed enough to push the page back, light enough to keep the chart the
| reader just clicked visible behind the drawer. Context is the whole point of
| a drawer over a full page.
*/
.analytics-detail-scrim { background: rgba(7, 27, 53, .28); }

/* Header ----------------------------------------------------------------- */
.analytics-detail-head { padding: 14px 16px 12px; gap: 12px; }
.analytics-detail-context { font-size: 9.5px; letter-spacing: .06em; }
.analytics-detail-head h2 { margin: 3px 0 0; font-size: 15.5px; }
.analytics-detail-scope { margin: 3px 0 0; font-size: 11px; }

/* Small enough to sit in a compact header, still a comfortable target. */
.analytics-detail-close { width: 32px; height: 32px; flex: 0 0 32px; }
.analytics-detail-close:hover { color: var(--interactive); background: var(--surface-hover); border-color: var(--border); }
.analytics-detail-close:focus-visible { outline: 0; box-shadow: var(--focus-ring); }

/* Body ------------------------------------------------------------------- */
.analytics-detail-body { gap: 12px; padding: 14px 16px; }

/* The headline count had a whole band of the drawer to itself. */
.analytics-detail-figure { gap: 8px; }
.analytics-detail-figure strong { font-size: 25px; }
.analytics-detail-figure span { font-size: 12px; }

.analytics-detail-note { padding: 9px 11px; font-size: 11.5px; line-height: 1.55; }
.analytics-detail-subhead { margin: 2px 0 0; font-size: 10px; }

/* Stat tiles: two to a row at this width, and no taller than they need. */
.analytics-detail-body .analytics-stat-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 9px;
}

.analytics-detail-body .analytics-stat { gap: 3px; padding: 10px 11px; border-radius: 10px; }
.analytics-detail-body .analytics-stat > span { font-size: 10px; line-height: 1.35; }
.analytics-detail-body .analytics-stat > strong { font-size: 18px; }
.analytics-detail-body .analytics-stat > strong.is-text { font-size: 12.5px; line-height: 1.35; }

/* Breakdown bars: closer together, and thinner, at drawer width. */
.analytics-detail-body .analytics-bars { gap: 9px; }
.analytics-detail-body .analytics-bar-row { gap: 4px; }
.analytics-detail-body .analytics-bar-head { gap: 10px; }
.analytics-detail-body .analytics-bar-name { font-size: 11.5px; }
.analytics-detail-body .analytics-bar-value { font-size: 11px; }
.analytics-detail-body .analytics-bar-track { height: 7px; }

/* A table in a drawer is a preview; Reports below is the full list. */
.analytics-detail-body .analytics-table th,
.analytics-detail-body .analytics-table td { padding: 6px 9px; font-size: 11px; }

.analytics-detail-body .analytics-explain summary { font-size: 11.5px; }
.analytics-detail-body .analytics-explain p { font-size: 11px; line-height: 1.55; }

/* Footer ----------------------------------------------------------------- */
/* Stays on the surface rather than scrolling away with the content. */
.analytics-detail-foot { padding: 11px 16px; }
.analytics-detail-foot .button { min-height: 34px; padding: 0 13px; font-size: 11.5px; }

@media (max-width: 720px) {
    .analytics-detail-figure strong { font-size: 23px; }
    .analytics-detail-body .analytics-stat-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

/*
| The page behind must not scroll while the drawer is open, or a flick of the
| wheel moves the wrong surface. The class is set by the drawer's own script,
| which also compensates for the scrollbar it removes.
*/
body.analytics-detail-open { overflow: hidden; }

/* ========================================================================
   Borrowing Demand Trend.

   Every rule is behind .analytics-line--trend or .analytics-trend-single, so
   the dual-line returns chart, which shares the base .analytics-line classes,
   is left exactly as it is.
   ======================================================================== */

.analytics-line--trend { gap: 0; }

/* Taller than the base plot: the line needs room to read as a shape. */
.analytics-line--trend .analytics-line-plot { height: 168px; }

/* The CSS grid overlay is replaced by real lines drawn in the SVG. */
.analytics-line--trend .analytics-line-grid { display: none; }

.analytics-line--trend .analytics-line-rule {
    stroke: var(--row-border);
    stroke-width: 1;
}

/* The axis the area sits on is stated a little more firmly than the rest. */
.analytics-line--trend .analytics-line-rule.is-base { stroke: var(--border); }

.analytics-line--trend .analytics-line-stroke { stroke-width: 2; }

/*
| Axis labels are positioned at their own point rather than shared out evenly,
| because the series is inset and even columns would not line up with it. The
| padding gives the first and last labels somewhere to overhang into.
*/
.analytics-line--trend .analytics-line-axis {
    position: relative;
    display: block;
    /* Room for two lines, so a long period name wraps instead of being cut. */
    height: 34px;
    padding: 7px 0 0;
    border-top: 0;
}

.analytics-line--trend .analytics-line-axis span {
    position: absolute;
    top: 7px;
    /*
     | Sized from the text, not from the gap left of the container edge. An
     | absolutely positioned box takes its shrink-to-fit width from `left` to
     | that edge, so the last label was squeezed into the final few percent and
     | wrapped while the first stayed on one line. max-content measures the
     | label itself, and the transform below is what brings it back inside.
     */
    width: max-content;
    max-width: 96px;
    transform: translateX(-50%);
    color: var(--text-muted);
    font-size: 9.5px;
    line-height: 1.25;
    text-align: center;
    /* Wrap rather than ellipsise, but never past two lines. */
    white-space: normal;
    overflow-wrap: break-word;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/*
| The end labels anchor inward instead of being centred on their point. A
| centred last label put half its width past the final x, which is at 95% of
| the plot, so the text ran outside the card. Anchoring the first to its left
| edge and the last to its right keeps both inside the inset the series
| already uses, and the middle labels stay centred over their own points.
*/
.analytics-line--trend .analytics-line-axis span:first-child {
    transform: translateX(0);
    text-align: left;
}

.analytics-line--trend .analytics-line-axis span:last-child {
    transform: translateX(-100%);
    text-align: right;
}

/* The value rides above its point, clear of the marker. */
.analytics-line--trend .analytics-line-tip { bottom: calc(100% - 3px); font-size: 10px; }

@media (max-width: 720px) {
    .analytics-line--trend .analytics-line-plot { height: 140px; }
    .analytics-line--trend .analytics-line-axis span { max-width: 74px; font-size: 9px; }
}

/* ---------------------------------------------------------------------- */
/* One bucket                                                             */
/* ---------------------------------------------------------------------- */
/*
| A single period was centred in the full height of the card, which read as a
| small number stranded in an empty panel. It now fills the width as a stated
| reading: the figure, the period it belongs to, and a bar that shows it is
| the whole of what was measured.
*/
.analytics-trend-single { display: block; place-items: initial; padding: 14px 15px; }

.analytics-trend-single-figure {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    justify-items: stretch;
    gap: 8px;
    width: 100%;
    padding: 14px 16px;
    border: 1px solid var(--border);
    border-radius: 11px;
    background: linear-gradient(180deg, rgba(37, 99, 235, .05), transparent 70%);
    color: inherit;
    text-decoration: none;
}

.analytics-trend-single-figure:hover { border-color: var(--interactive); }
.analytics-trend-single-figure:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.analytics-trend-single-value {
    display: flex;
    align-items: baseline;
    justify-content: flex-start;
    gap: 8px;
    color: var(--text-muted);
    font-size: 12px;
    font-weight: 650;
}

.analytics-trend-single-value strong {
    color: var(--heading);
    font-size: 32px;
    font-weight: 750;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

/* Full width, because one bucket is 100% of the period being read. */
.analytics-trend-single-bar {
    display: block;
    width: 100%;
    height: 8px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--surface-muted);
}

.analytics-trend-single-bar > span {
    display: block;
    width: 100%;
    height: 100%;
    border-radius: 999px;
    background: #2563eb;
}

.analytics-trend-single-label {
    color: var(--text-muted);
    font-size: 11px;
    text-align: left;
}

/* ======================================================================== */
/* Clickable cards                                                          */
/*                                                                          */
/* One affordance for every drill-down surface in Analytics. The card is not */
/* an anchor - several contain their own links - so the hit area comes from  */
/* script while a real link in the header carries focus and keyboard access. */
/* ======================================================================== */

.analytics-card[data-card-detail] {
    cursor: pointer;
    transition: border-color var(--motion) ease, box-shadow var(--motion) ease, transform var(--motion) ease;
}

.analytics-card[data-card-detail]:hover {
    border-color: var(--border-strong);
    box-shadow: 0 6px 18px rgba(7, 27, 53, .09);
    transform: translateY(-1px);
}

/* The header link takes focus, so the whole card shows the ring. */
.analytics-card[data-card-detail]:focus-within {
    border-color: var(--interactive);
    box-shadow: var(--focus-ring);
}

/* The chevron is affordance only; the whole surface activates. */
.analytics-card-open {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    flex-shrink: 0;
    margin-left: auto;
    color: var(--text-soft);
    border-radius: 7px;
    text-decoration: none;
    transition: color var(--motion) ease, background-color var(--motion) ease, transform var(--motion) ease;
}

.analytics-card[data-card-detail]:hover .analytics-card-open {
    color: var(--interactive);
    background: var(--blue-50);
    transform: translateX(2px);
}

.analytics-card-open:focus-visible { outline: none; color: var(--interactive); background: var(--blue-50); }

/* A card action sits before the chevron and keeps its own hit area. */
.analytics-card-head .analytics-card-action { margin-left: auto; }
.analytics-card-head .analytics-card-action + .analytics-card-open { margin-left: 4px; }

@media (prefers-reduced-motion: reduce) {
    .analytics-card[data-card-detail],
    .analytics-card-open { transition: none; }
    .analytics-card[data-card-detail]:hover { transform: none; }
    .analytics-card[data-card-detail]:hover .analytics-card-open { transform: none; }
}

/* ======================================================================== */
/* Overview - borrowing activity as bars                                    */
/*                                                                          */
/* Overview only. Demand & Utilization keeps the line plot, so none of these */
/* rules reach it. Each bucket is one flex column carrying its own value,    */
/* bar and label, which is what keeps a long period name inside the card:    */
/* the label can never be wider than the column it sits in.                  */
/* ======================================================================== */

.analytics-bars-chart { display: flex; width: 100%; min-width: 0; flex-direction: column; }

.analytics-bars-plot {
    position: relative;
    display: flex;
    align-items: stretch;
    justify-content: center;
    gap: 10px;
    min-width: 0;
    padding: 4px 2px 0;
}

/* Four hairlines and a baseline, behind the bars. */
.analytics-bars-grid {
    position: absolute;
    inset: 22px 0 34px 0;
    background-image: repeating-linear-gradient(
        to bottom,
        var(--row-border) 0,
        var(--row-border) 1px,
        transparent 1px,
        transparent calc(25% - 0.25px)
    );
    opacity: .85;
    pointer-events: none;
}

.analytics-bars-col {
    /* Positioned so the columns paint above the absolutely placed gridlines,
       which otherwise struck through every bar. */
    position: relative;
    display: flex;
    min-width: 0;
    flex: 1 1 0;
    max-width: 92px;
    flex-direction: column;
    align-items: center;
    padding: 0 2px;
    border-radius: 8px;
    color: inherit;
    text-decoration: none;
    transition: background-color var(--motion-fast) ease;
}

.analytics-bars-col:hover { background: var(--surface-hover); }
.analytics-bars-col:focus-visible { outline: none; box-shadow: var(--focus-ring); }

/*
| A definite height on purpose: each bar is sized as a percentage of it, and a
| percentage height against an auto-height parent collapses the bar entirely.
*/
.analytics-bars-track {
    display: flex;
    width: 100%;
    height: 150px;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
}

.analytics-bars-value {
    margin-bottom: 5px;
    color: var(--heading);
    font-size: 11px;
    font-weight: 750;
    line-height: 1;
    font-variant-numeric: tabular-nums;
}

.analytics-bars-bar {
    display: block;
    width: 100%;
    max-width: 44px;
    min-height: 3px;
    border-radius: 5px 5px 0 0;
    background: linear-gradient(180deg, #2563eb 0%, #60a5fa 100%);
    transition: filter var(--motion-fast) ease;
}

.analytics-bars-col:hover .analytics-bars-bar { filter: brightness(1.08); }

/* A recorded zero reads as a stub on the axis, not as a missing column. */
.analytics-bars-bar.is-zero { background: var(--border-strong); }

.analytics-bars-label {
    display: -webkit-box;
    width: 100%;
    margin-top: 8px;
    padding-top: 7px;
    overflow: hidden;
    border-top: 1px solid var(--row-border);
    color: var(--text-muted);
    font-size: 9.5px;
    line-height: 1.25;
    text-align: center;
    overflow-wrap: break-word;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
}

/* One bucket should read as a single column, not as a block filling the card. */
.analytics-bars-chart[data-points="1"] .analytics-bars-col { flex: 0 0 auto; width: 132px; max-width: 132px; }
.analytics-bars-chart[data-points="1"] .analytics-bars-bar { max-width: 64px; }

.analytics-bars-chart[data-points="2"] .analytics-bars-col,
.analytics-bars-chart[data-points="3"] .analytics-bars-col { max-width: 118px; }

@media (max-width: 720px) {
    .analytics-bars-plot { gap: 6px; }
    .analytics-bars-track { height: 124px; }
    .analytics-bars-bar { max-width: 34px; }
    .analytics-bars-label { font-size: 9px; }
    .analytics-bars-chart[data-points="1"] .analytics-bars-col { width: 108px; max-width: 108px; }
}

@media (prefers-reduced-motion: reduce) {
    .analytics-bars-col, .analytics-bars-bar { transition: none; }
}
</style>

{{--
    Accountability Oversight presentation layer.

    Styling only. Every rule here is scoped to the accountability page's own
    classes so no other module inherits it, and every colour goes through a
    semantic token so the dark theme stays correct.
--}}
<style>
/* Page header ------------------------------------------------------------ */
.accountability-page-heading { align-items: flex-start; margin-bottom: 16px; }
.accountability-page-heading h1 { margin: 2px 0 4px; font-size: clamp(20px, 1.6vw, 24px); }
.accountability-page-heading p:not(.eyebrow) { max-width: 680px; font-size: 12.5px; line-height: 1.5; }

/* Summary KPI row -------------------------------------------------------- */
.accountability-kpi-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }

.accountability-kpi-grid .accountability-kpi-card {
    display: grid;
    grid-template-columns: 32px minmax(0, 1fr);
    grid-template-rows: auto auto auto;
    align-content: center;
    column-gap: 11px;
    row-gap: 2px;
    min-height: 120px;
    height: 100%;
    padding: 14px 16px;
    color: inherit;
    text-decoration: none;
    transition: border-color var(--motion) ease, background-color var(--motion) ease, box-shadow var(--motion) ease;
}

.accountability-kpi-card .kpi-icon {
    grid-column: 1;
    grid-row: 1 / span 3;
    align-self: center;
    margin: 0;
}

.accountability-kpi-card .kpi-value { grid-column: 2; grid-row: 1; margin: 0; font-size: 25px; }
.accountability-kpi-card .kpi-label { grid-column: 2; grid-row: 2; font-size: 11.5px; line-height: 1.3; }

/* One line of context per card: longer strings ellipsise rather than
   pushing every card in the row taller. */
.accountability-kpi-card small {
    display: block;
    grid-column: 2;
    grid-row: 3;
    margin: 2px 0 0;
    overflow: hidden;
    color: var(--text-muted);
    font-size: 10.5px;
    line-height: 1.35;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.accountability-kpi-card:hover { border-color: var(--border-strong); box-shadow: var(--shadow); }
.accountability-kpi-card:focus-visible { outline: none; box-shadow: var(--focus-ring); }

/* Selected card reads as selected through its border, not a filled panel.
   Head/AO select a view server-side (.is-active); the borrower's cards
   filter the table already on the page client-side (.is-selected) — same
   look, different mechanism. */
.accountability-kpi-card.is-active,
.accountability-kpi-card.is-selected {
    border-color: var(--interactive);
    background: var(--info-bg);
    box-shadow: none;
}

/* Sub-navigation --------------------------------------------------------- */
.accountability-tabs {
    display: flex;
    gap: 5px;
    margin-bottom: 18px;
    padding: 4px;
    overflow-x: auto;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-subtle);
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}

.accountability-tab {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 7px;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid transparent;
    border-radius: 7px;
    color: var(--text-secondary);
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
    transition: border-color var(--motion) ease, background-color var(--motion) ease, color var(--motion) ease;
}

.accountability-tab:hover { color: var(--heading); background: var(--surface-hover); }
.accountability-tab:focus-visible { outline: none; box-shadow: var(--focus-ring); }

.accountability-tab.is-active {
    color: var(--heading);
    background: var(--surface-elevated);
    border-color: var(--border);
    box-shadow: var(--shadow-sm);
}

/* Section headings ------------------------------------------------------- */
.accountability-section-heading { align-items: flex-start; margin-bottom: 12px; }
.accountability-section-heading > div { min-width: 0; }
.accountability-section-heading h2 { margin: 0 0 3px; font-size: 15.5px; }
.accountability-section-heading p:not(.eyebrow) { max-width: 720px; margin: 0; color: var(--text-muted); font-size: 11.5px; line-height: 1.5; }
.accountability-section-heading .eyebrow { margin-bottom: 3px; }
.accountability-section-heading .status-badge { flex: 0 0 auto; }

/* Case cards ------------------------------------------------------------- */
.accountability-case-card { padding: 15px 17px; }
.accountability-case-card > .card-header { margin-bottom: 0; gap: 12px; }
.accountability-case-card > .card-header h3 { margin: 2px 0 0; font-size: 14px; }
.accountability-case-card > .card-header strong { color: var(--interactive); font-size: 11px; font-weight: 800; letter-spacing: .04em; }

.accountability-case-facts {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1px;
    margin: 12px 0 0;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--border);
}

.accountability-case-facts > div { min-width: 0; padding: 9px 11px; background: var(--surface); }
.accountability-case-facts dt { margin: 0 0 3px; color: var(--text-muted); font-size: 9.5px; font-weight: 800; letter-spacing: .05em; text-transform: uppercase; }
.accountability-case-facts dd { margin: 0; color: var(--heading); font-size: 12.5px; font-weight: 700; line-height: 1.35; overflow-wrap: anywhere; }
.accountability-case-facts dd small { display: block; margin-top: 2px; color: var(--text-muted); font-size: 10px; font-weight: 600; }

/* Compact inline alert: one bold line plus at most a short explanation. */
.accountability-alert { margin-top: 12px; padding: 9px 12px; font-size: 11.5px; line-height: 1.5; }
.accountability-alert strong { display: block; color: inherit; font-size: 12px; }
.accountability-alert p { margin: 2px 0 0; color: var(--text-secondary); }

.accountability-case-meta { margin: 10px 0 0; color: var(--text-muted); font-size: 10.5px; line-height: 1.5; }

/* Shown where a stage has no control for this role, so the card never ends
   in blank space. */
.accountability-case-state {
    display: inline-flex;
    width: max-content;
    max-width: 100%;
    align-items: center;
    gap: 7px;
    margin-top: 12px;
    padding: 5px 11px;
    border: 1px dashed var(--border-strong);
    border-radius: 999px;
    color: var(--text-muted);
    font-size: 11px;
    font-weight: 700;
}

.accountability-case-state::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--text-soft);
}

/* Compact empty states --------------------------------------------------- */
.accountability-empty {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 3px 10px;
    min-height: 0;
    padding: 12px 16px;
    line-height: 1.45;
}

.accountability-empty strong { color: var(--heading); font-size: 12.5px; }
.accountability-empty span { color: var(--text-muted); font-size: 11.5px; line-height: 1.5; }

/* Compact list bodies ---------------------------------------------------- */
.accountability-list-card { padding: 15px 17px; }
.accountability-list-card > .card-header { margin-bottom: 12px; }
.accountability-list-card .head-case-summary { margin-top: 12px; }
.accountability-list-card .head-case-summary > div { padding: 9px 11px; }
.accountability-list-card .head-case-summary dt { font-size: 9.5px; }
.accountability-list-card .head-case-summary dd { color: var(--heading); font-size: 12.5px; }

.accountability-list-card .billing-lines { margin: 12px 0 0; }
.accountability-list-card .billing-lines p { padding: 7px 0; font-size: 12px; }
.accountability-list-card .table-wrap table { min-width: 620px; }
.accountability-list-card .table-wrap td { padding: 8px 12px; font-size: 12px; }

.accountability-table-card { padding: 15px 17px; }
.accountability-table-card > .card-header { margin-bottom: 12px; }
.accountability-table-card td { padding: 8px 13px; font-size: 12px; }

/* Responsive ------------------------------------------------------------- */
@media (max-width: 1180px) {
    .accountability-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 900px) {
    .accountability-case-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
    .accountability-kpi-grid { grid-template-columns: minmax(0, 1fr); }
    .accountability-kpi-grid .accountability-kpi-card { min-height: 92px; }
    .accountability-case-facts { grid-template-columns: minmax(0, 1fr); }
    .accountability-section-heading { flex-direction: column; gap: 8px; }
}

@media (prefers-reduced-motion: reduce) {
    .accountability-kpi-card, .accountability-tab { transition: none; }
}

/* ========================================================================
   Option B — structured oversight layout.

   The page keeps its four counters and its tab row, and states the active
   caseload as a table: one row per case, with the key detail directly under
   the row it explains.
   ======================================================================== */

/* "As of" stamp ---------------------------------------------------------- */
.accountability-asof {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 6px;
    margin: 4px 0 0;
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 600;
    white-space: nowrap;
}

.accountability-asof .ui-icon { flex: 0 0 auto; color: var(--text-soft); }

/* A fourth counter tone, so Restrictions does not repeat the review amber. */
.kpi-accent-restriction {
    --kpi-accent: #6d4bb4;
    --kpi-icon-bg: #f0ebfb;
    --kpi-icon-border: #d9cdf3;
}

:root[data-theme="dark"] .kpi-accent-restriction,
html[data-theme="dark"] .kpi-accent-restriction {
    --kpi-accent: #b9a3e8;
    --kpi-icon-bg: #211a33;
    --kpi-icon-border: #3b2f57;
}

/* Sub-navigation --------------------------------------------------------- */
/* The selected view reads as selected on its own, without a count chip.
   The active/inactive/hover colors themselves live in the single
   .accountability-tab.is-active rule above — this block only adds icon color. */
.accountability-tab .ui-icon { flex: 0 0 auto; color: var(--text-soft); }
.accountability-tab.is-active .ui-icon,
.accountability-tab:hover:not(.is-active) .ui-icon { color: var(--interactive); }

/* Active caseload -------------------------------------------------------- */
.accountability-cases-card { padding: 0; overflow: hidden; }

.accountability-cases-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
    padding: 15px 17px;
    border-bottom: 1px solid var(--border);
}

.accountability-cases-head h2 {
    display: flex;
    align-items: center;
    gap: 9px;
    margin: 0;
    font-size: 15.5px;
}

.accountability-count-chip {
    min-width: 22px;
    padding: 2px 8px;
    border-radius: 999px;
    color: var(--info);
    background: var(--info-bg);
    font-size: 11.5px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    text-align: center;
}

.accountability-cases-tools { display: flex; align-items: center; gap: 8px; }

.accountability-search {
    position: relative;
    display: flex;
    align-items: center;
}

.accountability-search .ui-icon {
    position: absolute;
    left: 11px;
    color: var(--text-soft);
    pointer-events: none;
}

.accountability-search input {
    width: 260px;
    max-width: 100%;
    height: 34px;
    margin: 0;
    padding: 0 12px 0 33px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    color: var(--text-primary);
    font-size: 12px;
}

.accountability-search input::placeholder { color: var(--text-soft); }
.accountability-search input:focus-visible { outline: none; border-color: var(--interactive); box-shadow: var(--focus-ring); }
.accountability-search input::-webkit-search-cancel-button { cursor: pointer; }

.accountability-filter { position: relative; }

/* Sized off .icon-button, which the shared button rule deliberately skips. */
.accountability-filter-button {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    color: var(--text-secondary);
    cursor: pointer;
    transition: border-color var(--motion) ease, color var(--motion) ease, background-color var(--motion) ease;
}

.accountability-filter-button:hover { color: var(--interactive); border-color: var(--border-strong); }
.accountability-filter-button:focus-visible { outline: none; border-color: var(--interactive); box-shadow: var(--focus-ring); }
.accountability-filter-button[aria-expanded="true"] { color: var(--interactive); border-color: var(--interactive); background: var(--info-bg); }

/* Narrows the rows already rendered; it never re-queries. */
.accountability-filter-menu {
    position: absolute;
    z-index: 30;
    top: calc(100% + 6px);
    right: 0;
    display: grid;
    gap: 3px;
    min-width: 214px;
    padding: 9px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow);
}

.accountability-filter-menu__heading {
    margin: 0 0 3px;
    padding: 0 5px;
    color: var(--text-muted);
    font-size: 9.5px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.accountability-filter-menu label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    padding: 6px 6px;
    border-radius: 7px;
    color: var(--text-primary);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}

.accountability-filter-menu label:hover { background: var(--surface-hover); }
.accountability-filter-menu input { width: 15px; height: 15px; margin: 0; flex: 0 0 auto; }

/* Case table ------------------------------------------------------------- */
.accountability-cases-table table { min-width: 860px; }
.accountability-cases-table th { white-space: nowrap; }
.accountability-cases-table .is-numeric { text-align: right; font-variant-numeric: tabular-nums; }

.accountability-case-row > td { padding: 11px 14px; font-size: 12px; vertical-align: middle; }
.accountability-case-row > td:first-child { min-width: 210px; }

/* Reference above the person it belongs to, as one identity block. */
.accountability-case-ref {
    display: block;
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.accountability-case-borrower {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

/* A dot carries the tone at cell size, where a long label would not fit. */
.accountability-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
}

.accountability-status-pill::before {
    content: '';
    width: 6px;
    height: 6px;
    flex: 0 0 auto;
    border-radius: 50%;
    background: currentColor;
}

.accountability-row-none { color: var(--text-soft); }
.accountability-row-note { color: var(--text-muted); font-size: 11px; font-weight: 700; }
.accountability-row-action { min-height: 30px; padding: 0 14px; font-size: 11.5px; }

/* Row detail ------------------------------------------------------------- */
/* No bottom rule: the detail belongs to the row above, not beside it. */
.accountability-case-detail-row > td { padding: 0 14px 14px; border-bottom: 1px solid var(--row-border); }
.accountability-cases-table tbody tr:last-child > td { border-bottom: 0; }
.accountability-case-row > td { border-bottom: 0; }
.accountability-cases-table tbody tr:hover { background: transparent; }
.accountability-cases-table tbody tr.accountability-case-row:hover { background: var(--row-hover); }

.accountability-detail-panel {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--surface-subtle);
}

.accountability-detail-panel .ui-icon { flex: 0 0 auto; margin-top: 1px; }
.accountability-detail-panel > div { min-width: 0; }
.accountability-detail-panel strong { display: block; color: var(--heading); font-size: 12px; }
.accountability-detail-panel p { margin: 3px 0 0; color: var(--text-secondary); font-size: 11.5px; line-height: 1.5; }
.accountability-detail-panel small { display: block; margin-top: 4px; color: var(--text-muted); font-size: 10.5px; line-height: 1.5; }

.accountability-detail-panel.is-warning { border-color: var(--warning-border); background: var(--warning-bg); }
.accountability-detail-panel.is-warning .ui-icon,
.accountability-detail-panel.is-warning strong { color: var(--warning); }

.accountability-detail-panel.is-info { border-color: var(--info-border); background: var(--info-bg); }
.accountability-detail-panel.is-info .ui-icon,
.accountability-detail-panel.is-info strong { color: var(--info); }

/* The Head's decision forms, kept out of the Action cell. */
.accountability-detail-forms {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
    margin-top: 12px;
    padding: 13px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--surface);
}

.accountability-detail-forms .form-grid { margin: 0; }
.accountability-detail-forms textarea { min-height: 66px; }

/* Filtered-to-empty state ------------------------------------------------ */
.accountability-cases-none {
    margin: 0;
    padding: 14px 17px;
    border-top: 1px solid var(--border);
    color: var(--text-muted);
    font-size: 12px;
}

/* Scope hint --------------------------------------------------------------
   Subtle helper text, not a second callout: the tabs already say where the
   completed cases went, so this only needs to be legible, not prominent. */
.accountability-scope-hint {
    margin: 12px 0 0;
    color: var(--text-muted);
    font-size: 11.5px;
    line-height: 1.55;
}

/* Responsive ------------------------------------------------------------- */
@media (max-width: 900px) {
    .accountability-detail-forms { grid-template-columns: minmax(0, 1fr); }

    /*
     | Below the table's 860px minimum the row area scrolls sideways, and the
     | detail panel was scrolling with it: measured on a 390px screen, its text
     | wrapped at the table width and was cut mid-sentence.
     |
     | The panel explains the row rather than extending it, so it stays put
     | while the columns move. 100cqi is the scroller's visible width, which is
     | what the reader actually has, unlike 100vw.
     */
    .accountability-cases-table { container-type: inline-size; }
    .accountability-case-detail-row > td { padding-left: 0; padding-right: 0; }

    .accountability-detail-panel,
    .accountability-detail-forms {
        position: sticky;
        left: 14px;
        box-sizing: border-box;
        width: calc(100cqi - 28px);
    }
}

@media (max-width: 720px) {
    .accountability-cases-head { align-items: stretch; flex-direction: column; }
    .accountability-cases-tools { justify-content: space-between; }
    .accountability-search { flex: 1 1 auto; }
    .accountability-search input { width: 100%; }
}

@media (prefers-reduced-motion: reduce) {
    .accountability-filter-button { transition: none; }
}
</style>

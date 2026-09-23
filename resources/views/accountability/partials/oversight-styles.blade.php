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
.accountability-page-heading p:not(.eyebrow) { max-width: 680px; font-size: 12px; line-height: 1.5; }

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
.accountability-case-card > .card-header strong { color: var(--interactive); font-size: 10.5px; font-weight: 800; letter-spacing: .04em; }

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
@media (max-width: 900px) {
    .accountability-case-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
    .accountability-case-facts { grid-template-columns: minmax(0, 1fr); }
    .accountability-section-heading { flex-direction: column; gap: 8px; }
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

.accountability-case-workspace {
    display: grid;
    gap: 14px;
}

/* Compact record controls live inside the Active Cases card. They appear
   only when they can actually narrow the list, so a three-row queue does not
   waste a full card on filters. */
.accountability-browser-toolbar {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 10px;
    padding: 10px 16px 11px;
    border-bottom: 1px solid var(--border);
    background: var(--surface-subtle);
}

.accountability-browser-toolbar label {
    display: grid;
    gap: 5px;
    margin: 0;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 800;
}

.accountability-browser-search {
    flex: 1 1 280px;
    max-width: 440px;
}

.accountability-browser-filter {
    flex: 0 1 180px;
    min-width: 148px;
}

.accountability-browser-toolbar input,
.accountability-browser-toolbar select {
    width: 100%;
    min-height: 36px;
    margin: 0;
    font-size: 12px;
}

.accountability-browser-toolbar .search-input-shell { width: 100%; }

/* Case table ------------------------------------------------------------- */
.accountability-cases-table table { min-width: 800px; }
.accountability-cases-table th { padding-top: 9px; padding-bottom: 9px; white-space: nowrap; }
.accountability-cases-table td { padding-top: 10px; padding-bottom: 10px; line-height: 1.35; }
.accountability-cases-table .is-numeric { text-align: right; font-variant-numeric: tabular-nums; }

/* Inherits the universal .table-wrap th/td padding and font-size instead of a page-specific shrink. */
.accountability-case-row > td { vertical-align: middle; }
.accountability-case-row > td:first-child { min-width: 188px; }

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

/* The qualifier sits under the amount it belongs to, not beside it. */
.accountability-case-row > td > small {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 10px;
    line-height: 1.3;
}

.accountability-cases-card [data-case-toggle] {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 28px;
    padding: 3px 8px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    color: var(--interactive);
    font-size: 10.5px;
    font-weight: 800;
}

.accountability-cases-card [data-case-toggle]:hover,
.accountability-cases-card [data-case-toggle]:focus-visible {
    background: var(--surface-hover);
    border-color: var(--border-strong);
}

.accountability-cases-card [data-case-toggle][aria-expanded="true"] .accountability-toggle-chevron {
    transform: rotate(180deg);
}

.accountability-next-link {
    text-decoration: none;
}

.accountability-next-link > span:last-child {
    font-size: 13px;
    line-height: 1;
}

/* Waiting is a state, not a fake button. Only the role that can act gets a
   clickable control in the Next Action column. */
.accountability-next-state {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 700;
    line-height: 1.3;
    white-space: nowrap;
}

.accountability-next-state-group {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.accountability-view-detail {
    min-height: 26px !important;
    padding: 2px 6px !important;
    border: 0 !important;
    background: transparent !important;
    font-size: 10.5px !important;
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

.accountability-detail-stack { display: grid; gap: 10px; }

.accountability-detail-note {
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-subtle);
}

.accountability-detail-note strong {
    display: block;
    color: var(--heading);
    font-size: 11px;
    font-weight: 800;
}

.accountability-detail-note p {
    margin: 3px 0 0;
    color: var(--text-secondary);
    font-size: 10.75px;
    line-height: 1.45;
}

.accountability-detail-note small {
    display: block;
    margin-top: 4px;
    color: var(--text-muted);
    font-size: 10px;
    line-height: 1.45;
}

.accountability-detail-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 1px;
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--border);
}

.accountability-detail-grid > div {
    min-width: 0;
    padding: 9px 11px;
    background: var(--surface);
}

.accountability-detail-grid dt {
    margin: 0 0 3px;
    color: var(--text-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.accountability-detail-grid dd {
    margin: 0;
    color: var(--heading);
    font-size: 11.5px;
    font-weight: 700;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.accountability-document-list {
    display: grid;
    gap: 1px;
    overflow: hidden;
    margin-top: 10px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--border);
}

.accountability-document-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-height: 40px;
    padding: 6px 10px 6px 12px;
    background: var(--surface);
}

.accountability-document-item > span {
    color: var(--heading);
    font-size: 11.25px;
    font-weight: 700;
}

.accountability-document-item .table-action {
    flex: 0 0 auto;
    min-height: 28px;
    padding: 3px 7px;
    font-size: 10.75px;
}

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
    .accountability-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }

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

    .accountability-detail-note,
    .accountability-detail-grid,
    .accountability-action-body,
    .accountability-detail-forms {
        position: sticky;
        left: 14px;
        box-sizing: border-box;
        width: calc(100cqi - 28px);
    }
}

@media (max-width: 760px) {
    .accountability-browser-toolbar { align-items: stretch; }
    .accountability-browser-search,
    .accountability-browser-filter { flex: 1 1 100%; max-width: none; min-width: 0; }
    .accountability-detail-grid { grid-template-columns: minmax(0, 1fr); }
}


/* Sanction history -------------------------------------------------------
   Uses the same card/header/table rhythm as Active and Resolved cases. */
.accountability-sanctions-section { margin-top: 14px; }
.accountability-sanctions-table table { min-width: 850px; }
.accountability-sanctions-table th,
.accountability-sanctions-table td { vertical-align: middle; }
.accountability-sanctions-table td { font-size: 11.5px; }
.accountability-sanctions-table th { font-size: 10px; }

.accountability-sanction-row > td:first-child { min-width: 145px; }
.accountability-sanction-row > td:nth-child(3) { max-width: 340px; }

.accountability-sanction-offense,
.accountability-sanction-action {
    color: var(--heading);
    font-size: 11.5px;
    font-weight: 700;
}

.accountability-sanction-note {
    display: -webkit-box !important;
    margin-top: 2px !important;
    overflow: hidden;
    color: var(--text-muted) !important;
    font-size: 10px !important;
    font-weight: 500;
    line-height: 1.35 !important;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.accountability-sanction-row td > small:not(.accountability-sanction-note) {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 10px;
    line-height: 1.3;
}

.accountability-sanction-notice {
    gap: 6px;
    min-height: 30px;
    padding: 4px 8px;
    font-size: 10.75px;
}

@media (max-width: 760px) {
    .accountability-sanctions-table table { min-width: 760px; }
}

/* ========================================================================
   Accountability overview row.

   Four universal summary cards: white surface, colored top accent, a
   softly tinted icon tile, the reading name, figure, and one line of context.
   Navigation stays in the lists and explicit controls below.

   Named -overview rather than -summary because public/css/app.css already
   owns .accountability-summary for an older four-cell strip, and its
   descendant selectors would win over these class-level rules.
   ======================================================================== */

.accountability-overview {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 14px;
}

.accountability-overview-card {
    --tone: #1769e0;
    --tone-tile: rgba(23, 105, 224, .14);

    position: relative;
    display: grid;
    gap: 0;
    align-content: start;
    min-width: 0;
    padding: 18px 20px 20px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--surface-elevated);
    /* Single top accent: inset stripe only; do not pair with border-top. */
    box-shadow: inset 0 3px 0 var(--tone), var(--shadow-sm);
    color: inherit;
    text-decoration: none;
    cursor: default;
}


/* Summary cards are informational only. Navigation lives in the case lists,
   filters, and explicit action controls below. */
.accountability-overview-card:hover {
    border-color: var(--border);
    box-shadow: inset 0 3px 0 var(--tone), var(--shadow-sm);
    transform: none;
}
.accountability-overview-arrow { display: none; }

.accountability-overview-icon {
    display: inline-grid;
    width: 52px;
    height: 52px;
    place-items: center;
    margin-bottom: 14px;
    border-radius: 50%;
    color: var(--tone);
    background: var(--tone-tile);
}

.accountability-overview-label {
    color: var(--heading);
    font-size: 14.5px;
    font-weight: 700;
    line-height: 1.3;
}

.accountability-overview-value {
    margin-top: 6px;
    color: var(--heading);
    font-size: 31px;
    font-weight: 800;
    line-height: 1.1;
    font-variant-numeric: tabular-nums;
}

.accountability-overview-note {
    margin-top: 8px;
    color: var(--text-muted);
    font-size: 12px;
    line-height: 1.4;
}

/* Tones ------------------------------------------------------------------ */
.accountability-overview-card.tone-open {
    --tone: #2563eb;
    --tone-tile: rgba(37, 99, 235, .13);
}

.accountability-overview-card.tone-overdue {
    --tone: #dc2626;
    --tone-tile: rgba(220, 38, 38, .12);
}

.accountability-overview-card.tone-balance {
    --tone: #d97706;
    --tone-tile: rgba(217, 119, 6, .14);
}

.accountability-overview-card.tone-resolved {
    --tone: #16a34a;
    --tone-tile: rgba(22, 163, 74, .13);
}

html[data-theme="dark"] .accountability-overview-card.tone-open { --tone: #6aa6f5; }
html[data-theme="dark"] .accountability-overview-card.tone-overdue { --tone: #f08d82; }
html[data-theme="dark"] .accountability-overview-card.tone-balance { --tone: #e0b354; }
html[data-theme="dark"] .accountability-overview-card.tone-resolved { --tone: #5fc6a8; }

/* A peso amount is a longer string than a case count, so it takes less size. */
.accountability-overview-card.tone-balance .accountability-overview-value { font-size: 27px; }

/* Responsive ------------------------------------------------------------- */
@media (max-width: 1180px) {
    .accountability-overview { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 620px) {
    .accountability-overview { grid-template-columns: minmax(0, 1fr); }
    .accountability-overview-card { padding: 16px 18px 18px; }
    .accountability-overview-icon { width: 46px; height: 46px; margin-bottom: 12px; }
    .accountability-overview-value { font-size: 27px; }
}
</style>

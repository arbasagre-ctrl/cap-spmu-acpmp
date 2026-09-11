<style>
/*
|--------------------------------------------------------------------------
| Borrower - My Obligations (Grouped)
|--------------------------------------------------------------------------
|
| Borrower view only. Related technical records are presented as one
| obligation card so Incident + Billing + Restriction do not look like three
| different problems.
|
*/

.ob-workspace {
    display: grid;
    gap: 16px;
}

.ob-section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 16px;
}

.ob-section-heading span,
.ob-current-header > div > span {
    display: block;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.ob-section-heading p {
    margin: 3px 0 0;
    color: var(--text-muted);
    font-size: 12px;
}

/* Record summary --------------------------------------------------------- */

.ob-summary {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

/* Universal KPI card: matches Admin/AO/Borrower dashboard and the SPMU
   accountability summary cards in size, spacing, border and hover rhythm. */
.ob-summary-card {
    --ob-accent: var(--info);
    --ob-hover: rgba(23, 105, 224, .045);
    --ob-tint: #edf5fd;
    --ob-ink: #1d6fb8;

    position: relative;
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr);
    grid-template-rows: auto auto auto;
    column-gap: 12px;
    row-gap: 2px;
    min-width: 0;
    min-height: 122px;
    padding: 16px 18px;
    border: 1px solid var(--border);
    border-top: 3px solid var(--ob-accent);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: 0 1px 2px rgba(7, 27, 53, .05);
    transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease, background .16s ease;
}

.ob-summary-card.is-danger  { --ob-accent:#dc3545; --ob-hover:rgba(220,53,69,.04); --ob-tint:#fff1ef; --ob-ink:#c4493d; }
.ob-summary-card.is-warning { --ob-accent:#d99b16; --ob-hover:rgba(217,155,22,.05); --ob-tint:#fff8e9; --ob-ink:#a66a06; }
.ob-summary-card.is-info    { --ob-accent:#1769e0; --ob-hover:rgba(23,105,224,.045); --ob-tint:#edf5fd; --ob-ink:#1d6fb8; }
.ob-summary-card.is-orange  { --ob-accent:#ef7a29; --ob-hover:rgba(239,122,41,.05); --ob-tint:#fff3e9; --ob-ink:#bd5815; }
.ob-summary-card.is-success { --ob-accent:#159447; --ob-hover:rgba(21,148,71,.070); --ob-tint:#eefaf3; --ob-ink:#159447; }

.ob-summary-card:hover {
    transform: translateY(-1px);
    background: linear-gradient(var(--ob-hover), var(--ob-hover)), var(--surface);
    border-color: var(--border-strong);
    border-top-color: var(--ob-accent);
    box-shadow: 0 10px 24px rgba(7, 27, 53, .08);
}

.ob-summary-icon {
    grid-column: 1;
    grid-row: 1 / span 3;
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    align-self: center;
    color: var(--ob-ink);
    background: var(--ob-tint);
    border: 1px solid color-mix(in srgb, var(--ob-accent) 28%, var(--border));
    border-radius: 50%;
}

.ob-summary-label {
    grid-column: 2;
    grid-row: 1;
    align-self: end;
    color: var(--text-secondary);
    font-size: 11px;
    font-weight: 800;
    line-height: 1.25;
}

.ob-summary-value {
    grid-column: 2;
    grid-row: 2;
    display: block;
    color: var(--heading);
    font-size: 25px;
    font-weight: 800;
    line-height: 1.15;
}

.ob-summary-note {
    grid-column: 2;
    grid-row: 3;
    display: block;
    color: var(--text-muted);
    font-size: 10px;
    line-height: 1.35;
}

.ob-summary-card.is-empty { opacity: 1; }

html[data-theme="dark"] .ob-summary-card:hover { background: var(--surface-hover); }
html[data-theme="dark"] .ob-summary-card.is-danger,
html[data-theme="dark"] .ob-summary-card.is-warning,
html[data-theme="dark"] .ob-summary-card.is-info,
html[data-theme="dark"] .ob-summary-card.is-orange,
html[data-theme="dark"] .ob-summary-card.is-success {
    --ob-tint: var(--surface-subtle);
}

/* Current obligations heading ------------------------------------------- */

.ob-current-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-top: 2px;
}

.ob-current-header h2 {
    margin: 3px 0 0;
    color: var(--heading);
    font-size: 19px;
}

.ob-search {
    position: relative;
    display: flex;
    align-items: center;
    width: min(100%, 330px);
}

.ob-search > svg {
    position: absolute;
    left: 12px;
    color: var(--text-muted);
    pointer-events: none;
}

.ob-search input {
    width: 100%;
    min-height: 40px;
    margin: 0;
    padding-left: 38px;
    background: var(--input-bg);
}

/* Obligation cards ------------------------------------------------------- */

.ob-case-list {
    display: grid;
    gap: 12px;
}

.ob-case-card {
    padding: 18px 20px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}

.ob-case-card[hidden] { display: none; }

.ob-case-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 18px;
}

.ob-case-identity {
    display: flex;
    align-items: flex-start;
    gap: 13px;
    min-width: 0;
}

.ob-case-icon {
    --ob-tint: var(--surface-subtle);
    --ob-ink: var(--text-muted);

    display: grid;
    place-items: center;
    flex: 0 0 auto;
    width: 42px;
    height: 42px;
    color: var(--ob-ink);
    background: var(--ob-tint);
    border-radius: 11px;
}

.ob-case-icon.is-danger  { --ob-tint:#fdecee; --ob-ink:#b82938; }
.ob-case-icon.is-warning { --ob-tint:#fdf3dd; --ob-ink:#926307; }
.ob-case-icon.is-info    { --ob-tint:#e8f1fd; --ob-ink:#1157bd; }
.ob-case-icon.is-orange  { --ob-tint:#fdeee2; --ob-ink:#bd5815; }

html[data-theme="dark"] .ob-case-icon.is-danger  { --ob-tint:#351b1d; --ob-ink:#ff9b93; }
html[data-theme="dark"] .ob-case-icon.is-warning { --ob-tint:#332711; --ob-ink:#f3c56a; }
html[data-theme="dark"] .ob-case-icon.is-info    { --ob-tint:#14293d; --ob-ink:#86c6fb; }
html[data-theme="dark"] .ob-case-icon.is-orange  { --ob-tint:#33210f; --ob-ink:#f7ad6f; }

.ob-case-type {
    display: block;
    margin-bottom: 2px;
    color: var(--text-muted);
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.ob-case-identity h3 {
    margin: 0;
    color: var(--heading);
    font-size: 16px;
    line-height: 1.3;
}

.ob-case-identity small {
    display: block;
    margin-top: 3px;
    color: var(--text-muted);
    font-size: 11px;
}

.ob-case-state {
    display: grid;
    justify-items: end;
    gap: 5px;
    flex: 0 0 auto;
}

.ob-case-state strong {
    color: var(--heading);
    font-size: 13px;
}

.ob-badge {
    display: inline-flex;
    align-items: center;
    min-height: 24px;
    padding: 3px 10px;
    border: 1px solid transparent;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 750;
    white-space: nowrap;
}

.ob-badge.is-danger  { color:var(--danger); background:var(--danger-bg); border-color:var(--danger-border); }
.ob-badge.is-warning { color:var(--warning); background:var(--warning-bg); border-color:var(--warning-border); }
.ob-badge.is-info    { color:var(--info); background:var(--info-bg); border-color:var(--info-border); }
.ob-badge.is-success { color:var(--success); background:var(--success-bg); border-color:var(--success-border); }
.ob-badge.is-neutral { color:var(--neutral); background:var(--neutral-bg); border-color:var(--neutral-border); }

.ob-case-summary {
    margin: 12px 0 0 55px;
    color: var(--text-secondary);
    font-size: 12px;
    line-height: 1.45;
}

.ob-linked-restriction {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: 10px 0 0 55px;
    color: var(--warning);
    font-size: 11.5px;
    font-weight: 650;
}

.ob-next-action {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 14px 0 0 55px;
    padding: 10px 12px;
    border-left: 3px solid var(--border);
    background: var(--surface-subtle);
    border-radius: 7px;
}

.ob-next-action > span {
    flex: 0 0 auto;
    color: var(--text-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.ob-next-action > strong {
    color: var(--heading);
    font-size: 11.5px;
    line-height: 1.4;
}

.ob-next-action.is-warning { border-left-color: var(--warning); }
.ob-next-action.is-danger  { border-left-color: var(--danger); }
.ob-next-action.is-info    { border-left-color: var(--info); }

.ob-case-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin: 14px 0 0 55px;
}

.ob-details {
    position: relative;
}

.ob-details > summary {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 0 14px;
    color: var(--interactive);
    background: transparent;
    border: 1px solid var(--border);
    border-radius: 7px;
    cursor: pointer;
    font-size: 11.5px;
    font-weight: 750;
    list-style: none;
}

.ob-details > summary::-webkit-details-marker { display: none; }

.ob-details[open] {
    width: 100%;
}

.ob-details[open] > summary {
    margin-bottom: 10px;
    background: var(--surface-subtle);
}

.ob-detail-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 1px;
    width: 100%;
    overflow: hidden;
    background: var(--border);
    border: 1px solid var(--border);
    border-radius: 8px;
}

.ob-detail-grid > div {
    display: grid;
    gap: 3px;
    min-width: 0;
    padding: 10px 12px;
    background: var(--surface);
}

.ob-detail-grid small {
    color: var(--text-muted);
    font-size: 9px;
    font-weight: 800;
    letter-spacing: .04em;
    text-transform: uppercase;
}

.ob-detail-grid strong {
    color: var(--heading);
    font-size: 11.5px;
    line-height: 1.35;
    overflow-wrap: anywhere;
}

.ob-clear-card {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 18px 20px;
    color: var(--success);
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: var(--shadow-sm);
}

.ob-clear-card strong {
    display: block;
    color: var(--heading);
    font-size: 14px;
}

.ob-clear-card p {
    margin: 3px 0 0;
    color: var(--text-muted);
    font-size: 11.5px;
}

.ob-no-match {
    padding: 24px;
    color: var(--text-muted);
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 10px;
    text-align: center;
}

/* Responsive ------------------------------------------------------------- */

@media (max-width: 1080px) {
    .ob-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .ob-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 700px) {
    .ob-summary { grid-template-columns: 1fr; }

    .ob-current-header {
        align-items: stretch;
        flex-direction: column;
    }

    .ob-search { width: 100%; }

    .ob-case-top {
        flex-direction: column;
    }

    .ob-case-state {
        justify-items: start;
        margin-left: 55px;
    }

    .ob-case-summary,
    .ob-linked-restriction,
    .ob-next-action,
    .ob-case-actions {
        margin-left: 0;
    }

    .ob-next-action {
        align-items: flex-start;
        flex-direction: column;
        gap: 4px;
    }

    .ob-detail-grid { grid-template-columns: 1fr; }
}

/* Borrower summary cards use the same KPI language as the dashboards and
   AO/Admin accountability summary: white body, colored top accent, tinted icon. */
.ob-summary-card {
    border-top:3px solid var(--ob-accent);
    box-shadow:0 1px 2px rgba(7, 27, 53, .05);
    transition:transform .16s ease, border-color .16s ease, box-shadow .16s ease, background .16s ease;
}
.ob-summary-card:hover {
    transform:translateY(-1px);
    border-color:var(--border-strong);
    border-top-color:var(--ob-accent);
    background:linear-gradient(color-mix(in srgb, var(--ob-accent) 7%, transparent), color-mix(in srgb, var(--ob-accent) 7%, transparent)), var(--surface-elevated);
    box-shadow:0 8px 20px rgba(7, 27, 53, .07);
}
.ob-summary-icon {
    border:1px solid color-mix(in srgb, var(--ob-accent) 28%, transparent);
    border-radius:8px;
}
.ob-summary-card.is-empty { opacity:1; }
html[data-theme="dark"] .ob-summary-card:hover { background:var(--surface-hover); }


/* -----------------------------------------------------------------------
| My Obligations summary cards — exact dashboard KPI proportions
| Matches the SPMU Head / Administrator dashboard card dimensions and
| information hierarchy while remaining non-clickable summary content.
| ------------------------------------------------------------------------ */
.ob-summary-card {
    position: relative;
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr);
    grid-template-rows: auto auto auto;
    column-gap: 12px;
    row-gap: 2px;
    min-height: 122px;
    padding: 16px 18px;
    align-items: initial;
    border: 1px solid var(--border);
    border-top: 3px solid var(--ob-accent);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: 0 1px 2px rgba(7, 27, 53, .05);
}

.ob-summary-copy {
    display: contents;
}

.ob-summary-icon {
    grid-column: 1;
    grid-row: 1 / span 3;
    display: grid;
    place-items: center;
    align-self: center;
    width: 44px;
    height: 44px;
    margin: 0;
    border: 1px solid color-mix(in srgb, var(--ob-accent) 28%, transparent);
    border-radius: 50%;
}

.ob-summary-label {
    grid-column: 2;
    grid-row: 1;
    align-self: end;
    margin: 0;
    color: var(--text-secondary);
    font-size: 11px;
    font-weight: 800;
    line-height: 1.25;
}

.ob-summary-value {
    grid-column: 2;
    grid-row: 2;
    display: block;
    margin: 0;
    color: var(--heading);
    font-size: 25px;
    font-weight: 800;
    line-height: 1.15;
}

.ob-summary-note {
    grid-column: 2;
    grid-row: 3;
    display: block;
    margin: 0;
    color: var(--text-muted);
    font-size: 10px;
    line-height: 1.35;
}

@media (max-width: 1300px) {
    .ob-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 680px) {
    .ob-summary { grid-template-columns: 1fr; }
}

/* Resolved History disclosure --------------------------------------------
   Kept out of the way by default so My Obligations stays one clean, active
   workspace; the borrower's own resolved records remain one click away. */
.ob-resolved-history-disclosure {
    margin-top: 4px;
    padding: 16px 18px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 12px;
}

.ob-resolved-history-disclosure > summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    cursor: pointer;
    color: var(--heading);
    font-size: 14px;
    font-weight: 800;
    list-style: none;
}

.ob-resolved-history-disclosure > summary::-webkit-details-marker { display: none; }

.ob-resolved-history-disclosure[open] > summary { margin-bottom: 14px; }

.ob-resolved-history-body { padding-top: 2px; }
.ob-resolved-history-body > .content-area { margin: 0; }

</style>
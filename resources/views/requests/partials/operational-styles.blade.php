<style>
/* Only the approved SPMU request detail uses this compact presentation. */
.request-operational-page {
    --request-blue: #0863db;
    --request-green: #00a20b;
    font-size: 13px;
}
.request-operational-page .request-operational-heading {
    align-items: center;
    flex-direction: row;
    gap: 20px;
    margin-bottom: 22px;
}
.request-operational-page .request-operational-title-row {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
}
.request-operational-page .request-operational-title-row h1 {
    margin: 0;
    font-size: clamp(25px, 2.1vw, 32px);
    font-weight: 750;
    line-height: 1.2;
    overflow-wrap: anywhere;
}
.request-operational-page .request-operational-title-row .status-badge {
    flex-shrink: 0;
    padding: 5px 10px;
    font-size: 11px;
    white-space: nowrap;
}
.request-operational-page .request-operational-identity > p {
    margin: 6px 0 0;
    color: var(--text-muted);
    font-size: 16px;
}
.request-operational-page .request-operational-next-action {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-shrink: 0;
    padding: 7px 12px 7px 16px;
    border: 1px solid var(--row-border);
    border-radius: 9px;
    background: var(--surface-elevated);
}
.request-operational-page .request-operational-next-action p {
    margin: 0;
    color: var(--text);
    font-size: 13px;
}
.request-operational-next-action p > span { color: var(--text-muted); }
.request-operational-page .button.request-custody-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 42px;
    padding: 10px 14px;
    border-color: var(--request-blue);
    border-radius: 8px;
    color: #fff;
    background: var(--request-blue);
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    transition: background 160ms ease, border-color 160ms ease;
}
.request-operational-page .button.request-custody-link:hover,
.request-operational-page .button.request-custody-link:focus-visible {
    border-color: #0452bc;
    color: #fff;
    background: #0452bc;
}
.request-operational-page .request-tracker-card { margin-top: 0; }
.request-operational-page .request-tracker {
    padding: 18px 16px;
    border-radius: 11px;
}
.request-operational-page .request-tracker__scroll { padding: 8px 2px 6px; }
.request-operational-page .request-tracker__rail {
    min-width: 900px;
    grid-template-columns: repeat(8, minmax(0, 1fr));
}
.request-operational-page .request-tracker__step { padding: 0 5px; }
.request-operational-page .request-tracker__step::after {
    top: 20px;
    left: calc(50% + 24px);
    width: calc(100% - 48px);
    height: 2px;
}
.request-operational-page .request-tracker__marker {
    width: 40px;
    height: 40px;
    margin-bottom: 10px;
}
.request-operational-page .request-tracker__step.is-complete::after {
    background: var(--request-green);
}
.request-operational-page .request-tracker__step.is-complete .request-tracker__marker {
    border-color: #00950b;
    background: linear-gradient(180deg, #00b50e, #009009);
    color: #fff;
}
.request-operational-page .request-tracker__step.is-current .request-tracker__marker {
    border-color: var(--request-blue);
    color: var(--request-blue);
    box-shadow: 0 0 0 4px var(--surface-elevated), 0 0 0 6px var(--info-bg);
}
.request-operational-page .request-tracker__step.is-current .request-tracker__copy strong {
    color: var(--request-blue);
}
.request-operational-page .request-tracker__step.is-pending .request-tracker__copy { opacity: 1; }
.request-operational-page .request-tracker__step.is-pending .request-tracker__copy strong { color: var(--text-muted); }
.request-operational-page .request-tracker__copy strong { font-size: 12px; }
.request-operational-page .request-tracker__copy time,
.request-operational-page .request-tracker__pending-label { font-size: 11px; }
.request-operational-page .request-operational-grid {
    grid-template-columns: minmax(0, .97fr) minmax(0, 1.03fr);
    gap: 20px;
    align-items: stretch;
    margin-top: 18px;
}
.request-operational-page .request-information-card,
.request-operational-page .request-documents-card {
    min-width: 0;
    padding: 0;
    overflow: hidden;
}
.request-operational-page .request-section-title {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    margin: 0;
    color: var(--heading);
    font-size: 16px;
    font-weight: 650;
    line-height: 1.35;
}
.request-operational-page .request-section-title h2 {
    margin: 0;
    font-size: inherit;
    font-weight: inherit;
}
.request-operational-page .request-section-title > .ui-icon {
    flex-shrink: 0;
    color: var(--text-muted);
}
.request-operational-page .request-operational-grid .card-header {
    padding: 13px 20px 11px;
    border-bottom: 1px solid var(--row-border);
    background: transparent;
}
.request-operational-page .request-information-list {
    grid-template-columns: minmax(150px, .65fr) minmax(0, 1fr);
    margin: 6px 18px 7px;
}
.request-operational-page .request-information-list dt,
.request-operational-page .request-information-list dd {
    padding: 6px 4px;
    border-bottom: 1px solid var(--row-border);
    font-size: 13px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}
.request-operational-page .request-information-list dt { padding-right: 12px; }
.request-operational-page .request-information-list dt:last-of-type,
.request-operational-page .request-information-list dd:last-of-type { border-bottom: 0; }
.request-operational-page .request-operational-document-list { padding: 0 16px 14px; }
.request-operational-page .request-operational-document {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    min-height: 70px;
    padding: 15px 6px;
    border-bottom: 1px solid var(--row-border);
}
.request-operational-page .request-operational-document:last-child { border-bottom: 0; }
.request-operational-page .request-operational-document-copy {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 5px 16px;
    min-width: 0;
}
.request-operational-document-copy strong { font-size: 12px; font-weight: 700; }
.request-operational-document-copy small { font-size: 12px; }
.request-operational-page .request-operational-document-copy strong { color: var(--heading); }
.request-operational-page .button.request-document-link {
    flex: 0 0 auto;
    min-width: 64px;
    min-height: 35px;
    padding: 7px 14px;
    border: 1px solid var(--request-blue);
    border-radius: 6px;
    color: var(--request-blue);
    background: var(--surface-elevated);
    font-size: 12px;
    font-weight: 700;
    transition: background 160ms ease, color 160ms ease;
}
.request-operational-page .button.request-document-link:hover,
.request-operational-page .button.request-document-link:focus-visible {
    color: #fff;
    background: var(--request-blue);
}
.request-operational-page .request-items-card { padding: 12px 17px 7px; }
.request-operational-page .request-items-card .card-header {
    padding: 0 3px 10px;
    border: 0;
    background: transparent;
}
.request-operational-page .table-wrap { overflow-x: auto; box-shadow: none; }
.request-operational-page .request-operational-items { min-width: 590px; }
.request-operational-page .request-operational-items th {
    padding: 7px 14px;
    font-size: 11px;
    letter-spacing: 0;
    text-transform: none;
}
.request-operational-page .request-operational-items td {
    padding: 6px 14px;
    font-size: 13px;
    line-height: 1.4;
}
.request-operational-page .request-operational-items th:first-child { width: 45%; }
.request-operational-page .request-operational-items th:nth-child(2),
.request-operational-page .request-operational-items th:nth-child(3) { width: 15%; }
.request-operational-page .request-activity-history { padding: 0; }
.request-operational-page .request-activity-summary {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 13px 20px;
    border-radius: inherit;
    cursor: pointer;
    list-style: none;
}
/* Base accordion styling lives in requests/partials/audit-history.blade.php,
   which both this layout and the SPMU review layout include. Only the
   operational-page spacing overrides remain here. */
.request-operational-page .request-activity-history > .table-wrap { margin: 0 18px 18px; }
html[data-theme="dark"] .request-operational-page { --request-blue: #72b7f4; }
html[data-theme="dark"] .request-operational-page .request-custody-link { background: #0863db; border-color: #0863db; }
@media (max-width: 1280px) {
    .request-operational-page .request-operational-heading { flex-wrap: wrap; }
    .request-operational-page .request-operational-next-action { margin-left: auto; }
}
@media (max-width: 1000px) {
    .request-operational-page .request-operational-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 600px) {
    .request-operational-page .request-operational-next-action {
        width: 100%;
        flex-wrap: wrap;
        gap: 10px;
        padding: 12px;
    }
    .request-operational-page .request-custody-link { width: 100%; }
    .request-operational-page .request-information-list { grid-template-columns: minmax(115px, .7fr) minmax(0, 1fr); }
    .request-operational-page .request-information-list dt { border-bottom: 1px solid var(--row-border); }
    .request-operational-page .request-information-list dt:last-of-type { border-bottom: 0; }
    .request-operational-page .request-activity-summary { align-items: flex-start; flex-direction: column; gap: 10px; }
}
@media (prefers-reduced-motion: reduce) {
    .request-operational-page *, .request-operational-page *::after { transition: none !important; }
}
</style>

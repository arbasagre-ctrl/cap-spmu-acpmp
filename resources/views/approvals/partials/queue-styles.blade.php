<style>
.approval-queue { --approval-blue: #0865df; width: 100%; min-width: 0; font-size: 13px; }
.approval-queue [hidden] { display: none !important; }

/* Heading */
.approval-queue .approval-queue-heading { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; gap: 12px; margin-bottom: 14px; }
.approval-queue-heading .eyebrow { margin-bottom: 10px; }
.approval-queue .approval-queue-heading h1 { margin: 0 0 9px; }
.approval-queue .approval-queue-heading > div > p:last-child { margin: 0; font-size: 13px; line-height: 1.55; }
.approval-queue .button.approval-queue-records { flex-shrink: 0; gap: 9px; min-height: 40px; padding: 10px 15px; border: 1px solid var(--approval-blue); border-radius: 7px; background: var(--surface-elevated); color: var(--approval-blue); font-size: 12px; white-space: nowrap; }
.approval-queue-heading > .approval-queue-records { justify-self: end; }
.approval-queue-records .ui-icon { flex-shrink: 0; }
.approval-queue .button.approval-queue-records:hover, .approval-queue .button.approval-queue-records:focus-visible { color: #fff; background: var(--approval-blue); border-color: var(--approval-blue); }

/* Search and sort */
.approval-queue-filters { display: grid; grid-template-columns: minmax(0, 2.6fr) minmax(170px, 1fr); align-items: end; gap: 14px 18px; margin-bottom: 16px; padding: 17px 18px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.approval-queue-filters label { min-width: 0; margin: 0; display: grid; gap: 8px; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.approval-queue-filters input, .approval-queue-filters select { width: 100%; min-height: 40px; border-radius: 7px; font-size: 12px; }
.approval-queue-filters .search-input-shell input { padding-left: 36px; }

/* Pending approvals table */
.approval-queue-card { padding: 0; overflow: hidden; }
.approval-queue-card-heading { margin: 0; padding: 15px 18px; border-bottom: 1px solid var(--border); color: var(--text-secondary); font-size: 11px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
.approval-queue-table-wrap { width: 100%; min-width: 0; overflow-x: auto; }
.approval-queue .approval-queue-table { width: 100%; min-width: 880px; margin: 0; border-collapse: collapse; }
.approval-queue .approval-queue-table th { padding: 12px 15px; border-bottom: 1px solid var(--border); background: var(--table-heading-bg); color: var(--text-secondary); font-size: 10px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; text-align: left; white-space: nowrap; }
.approval-queue .approval-queue-table td { padding: 15px; border-bottom: 1px solid var(--row-border); color: var(--heading); font-size: 12px; line-height: 1.55; vertical-align: middle; }
.approval-queue-table tbody tr:last-child td { border-bottom: 0; }
.approval-queue-table tbody tr:hover td { background: var(--row-hover); }
.approval-queue-table th:nth-child(1) { width: 14%; }
.approval-queue-table th:nth-child(2) { width: 20%; }
.approval-queue-table th:nth-child(3) { width: 21%; }
.approval-queue-table th:nth-child(4) { width: 16%; }
.approval-queue-table th:nth-child(5) { width: 16%; }
.approval-queue-table th:nth-child(6) { width: 13%; }
.approval-queue-no { white-space: nowrap; font-weight: 650; }
.approval-queue-borrower strong { display: block; color: var(--heading); font-size: 12px; font-weight: 700; }
.approval-queue-borrower small { display: block; color: var(--text-muted); font-size: 11px; }
.approval-queue-submitted time, .approval-queue-submitted time > span { display: block; }
.approval-queue-submitted time { white-space: nowrap; }
.approval-queue .button.approval-queue-review { min-height: 33px; padding: 7px 14px; border-color: var(--approval-blue); border-radius: 6px; background: var(--surface-elevated); color: var(--approval-blue); font-size: 11px; white-space: nowrap; }
.approval-queue .button.approval-queue-review:hover, .approval-queue .button.approval-queue-review:focus-visible { color: #fff; background: var(--approval-blue); border-color: var(--approval-blue); }

/* Footer / pagination */
.approval-queue-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 18px; padding: 16px 18px; border-top: 1px solid var(--border); }
.approval-queue-footer > p { margin: 0; color: var(--text-secondary); font-size: 12px; }
.approval-queue-pagination { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 7px; margin-left: auto; }
.approval-queue-page { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 5px 9px; border: 1px solid var(--border); border-radius: 5px; background: var(--surface-elevated); color: var(--text-secondary); font: inherit; font-size: 11px; line-height: 1; cursor: pointer; }
.approval-queue-page:hover:not(:disabled):not(.is-active) { color: var(--approval-blue); border-color: var(--approval-blue); }
.approval-queue-page.is-active { color: #fff; background: var(--approval-blue); border-color: var(--approval-blue); font-weight: 750; }
.approval-queue-page:disabled { color: var(--text-soft); background: var(--surface-subtle); cursor: not-allowed; }
.approval-queue-page-previous { transform: rotate(180deg); }
.approval-queue-page-ellipsis { padding: 0 3px; color: var(--text-muted); font-size: 11px; }

/* Empty states */
.approval-queue-empty { display: flex; min-height: clamp(340px, 46vh, 470px); align-items: center; justify-content: center; padding: 44px 22px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); text-align: center; }
.approval-queue-empty-content { display: flex; flex-direction: column; align-items: center; max-width: 440px; }
.approval-queue-empty-icon { display: grid; place-items: center; width: 92px; height: 92px; margin-bottom: 26px; border-radius: 50%; background: var(--info-bg); color: var(--approval-blue); }
.approval-queue .approval-queue-empty h2 { margin: 0 0 11px; font-size: 18px; font-weight: 750; line-height: 1.45; }
.approval-queue-empty p { margin: 0; color: var(--text-secondary); font-size: 13px; line-height: 1.75; }
.approval-queue-no-results { min-height: 0; padding: 40px 22px; border: 0; border-radius: 0; }
.approval-queue-no-results .approval-queue-empty-icon { width: 64px; height: 64px; margin-bottom: 18px; }
.approval-queue-no-results h2 { font-size: 15px; }

html[data-theme="dark"] .approval-queue { --approval-blue: #72b7f4; }
html[data-theme="dark"] .approval-queue-page.is-active,
html[data-theme="dark"] .approval-queue .button.approval-queue-review:hover,
html[data-theme="dark"] .approval-queue .button.approval-queue-records:hover { color: var(--navy-950); }

@media (max-width: 760px) {
    .approval-queue .approval-queue-heading { gap: 12px; margin-bottom: 14px; }
    .approval-queue-filters { grid-template-columns: minmax(0, 1fr); padding: 14px; }
    .approval-queue-footer { align-items: flex-start; padding: 14px; }
    .approval-queue-pagination { margin-left: 0; }
    .approval-queue-empty { min-height: 320px; padding: 34px 16px; }
    .approval-queue-empty p br { display: none; }
}
@media (max-width: 450px) {
    .approval-queue-pagination { gap: 6px; }
    .approval-queue-page { min-width: 30px; height: 32px; padding: 5px 8px; }
}
</style>

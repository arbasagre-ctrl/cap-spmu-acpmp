<style>
/* Only the Action Officer's return workspace uses these presentation rules. */
.return-flow-page { --return-blue: #0863db; font-size: 13px; }
.return-flow-page [hidden] { display: none !important; }
.return-flow-page, .return-flow-page #return-primary { scroll-margin-top: 90px; }
.return-flow-page .return-page-stack { display: grid; align-content: start; gap: 24px; }
.return-flow-page .content-area, .return-flow-page .content-grid { margin: 0; }
.return-flow-page .return-history-section { margin-top: 24px; }

.return-flow-page .return-top-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; align-items: stretch; }
.return-flow-page .return-top-grid.summary-only { grid-template-columns: minmax(0, 1fr); }
.return-flow-page .return-top-grid > .content-area { min-width: 0; height: 100%; }
.return-flow-page .return-top-grid > .content-area > .card { height: 100%; }
.return-flow-page .return-top-grid.has-documents .return-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.return-flow-page .card { min-width: 0; padding: 18px 20px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); box-shadow: none; }
.return-flow-page .card-header { display: flex; flex-direction: row; align-items: flex-start; justify-content: space-between; gap: 12px; margin: 0 0 12px; padding: 0; border: 0; border-radius: 0; background: transparent; }
.return-flow-page .eyebrow { margin: 0 0 4px; color: var(--text-secondary); font-size: 11px; font-weight: 750; line-height: 1.5; letter-spacing: .04em; }
.return-flow-page h2 { margin: 0; color: var(--heading); font-size: clamp(17px, 1.2vw, 20px); font-weight: 650; line-height: 1.4; }
.return-flow-page .notice { margin: 0; padding: 10px 14px; align-items: center; }
.return-flow-page .return-flash { gap: 12px; }
.return-flow-page .return-flash > div { flex: 1; min-width: 0; }
.return-flow-page .return-flash-dismiss { flex: 0 0 26px; width: 26px; height: 26px; padding: 0; }
.return-flow-page .return-flash-dismiss .ui-icon { display: block; width: 18px; height: 18px; }
.return-flow-page .detail-list .ui-icon,
.return-flow-page .return-summary-grid .ui-icon,
.return-flow-page .return-document-copy > .ui-icon { flex-shrink: 0; width: 20px; height: 20px; color: var(--return-blue); }

.return-flow-page .return-summary-card .card-header { margin-bottom: 16px; }
.return-flow-page .return-summary-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 24px;
    margin: 0;
}
.return-flow-page .return-summary-item { min-width: 0; padding: 10px 0; border-top: 1px solid var(--row-border); }
.return-flow-page .return-summary-item dt,
.return-flow-page .return-summary-item dd { margin: 0; }
.return-flow-page .return-summary-item dt { display: flex; align-items: flex-start; gap: 9px; color: var(--text-secondary); font-size: 12px; font-weight: 600; }
.return-flow-page .return-summary-item dd { margin-top: 6px; padding-left: 29px; color: var(--heading); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
.return-flow-page .return-summary-notes { display: grid; gap: 10px; margin-top: 12px; }
.return-flow-page .return-summary-note { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding-top: 12px; border-top: 1px solid var(--row-border); }
.return-flow-page .return-summary-note strong,
.return-flow-page .return-summary-note span { display: block; }
.return-flow-page .return-summary-note strong { color: var(--heading); font-size: 12px; }
.return-flow-page .return-summary-note span { margin-top: 2px; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
.return-flow-page .return-summary-note-action { align-items: center; }

.return-flow-page .return-document-list { display: grid; }
.return-flow-page .return-document-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--row-border); }
.return-flow-page .return-document-row:first-child { padding-top: 0; border-top: 0; }
.return-flow-page .return-document-row:last-child { padding-bottom: 0; }
.return-flow-page .return-document-row-stacked { align-items: stretch; }
.return-flow-page .return-document-row-main { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.return-flow-page .return-document-copy { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
.return-flow-page .return-document-copy strong,
.return-flow-page .return-document-copy small { display: block; }
.return-flow-page .return-document-copy strong { color: var(--heading); font-size: 13px; }
.return-flow-page .return-document-copy small { max-width: 760px; margin-top: 2px; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
.return-flow-page .return-document-actions { display: inline-flex; align-items: center; justify-content: flex-end; gap: 8px; flex: 0 0 auto; }
.return-flow-page .return-navigation-action { display: inline-flex; align-items: center; justify-content: center; gap: 7px; }
.return-flow-page .return-document-disclosure { display: block; width: 100%; padding: 12px 0; border-top: 1px solid var(--row-border); }
.return-flow-page .return-document-disclosure:first-child { padding-top: 0; border-top: 0; }
.return-flow-page .return-document-disclosure:last-child { padding-bottom: 0; }
.return-flow-page .return-document-disclosure > summary { list-style: none; cursor: pointer; }
.return-flow-page .return-document-disclosure > summary::-webkit-details-marker { display: none; }
.return-flow-page .return-document-disclosure-summary { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 42px; }
.return-flow-page .return-disclosure-trigger { display: inline-flex; align-items: center; justify-content: center; gap: 7px; flex: 0 0 auto; min-width: 128px; pointer-events: none; }
.return-flow-page .return-disclosure-chevron { transition: transform .16s ease; }
.return-flow-page .return-document-disclosure[open] .return-disclosure-chevron { transform: rotate(180deg); }
.return-flow-page .return-document-disclosure .return-laundry-form-upload { margin-top: 12px; }
.return-flow-page .return-laundry-form-upload { display: grid; gap: 10px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-subtle); }
.return-flow-page .return-laundry-form-upload label { display: grid; gap: 6px; color: var(--heading); font-size: 12px; font-weight: 700; }
.return-flow-page .return-laundry-form-upload label small { color: var(--text-muted); font-size: 11px; font-weight: 400; }
.return-flow-page .return-laundry-form-upload input { width: 100%; }
.return-flow-page .return-laundry-form-upload .button { justify-self: start; }

.return-flow-page .return-inspection-card { display: grid; gap: 12px; }
.return-flow-page .return-inspection-header { margin-bottom: 0; align-items: center; }
.return-flow-page .return-outstanding-badge { padding: 6px 14px; color: var(--return-blue); border-color: var(--info-border); background: var(--info-bg); font-size: 12px; white-space: nowrap; }
.return-flow-page .return-inspection-scroll { min-width: 0; overflow-x: auto; overscroll-behavior-x: contain; border: 1px solid var(--border); border-radius: 7px; box-shadow: none; }
.return-flow-page .return-inspection-scroll table { width: 100%; min-width: 700px; margin: 0; table-layout: fixed; border-collapse: collapse; }
.return-flow-page .return-item-column { width: 29%; }
.return-flow-page .return-condition-column { width: 9.75%; }
.return-flow-page .return-total-column { width: 12.5%; }
.return-flow-page .return-inspection-table th { padding: 9px 5px; border-bottom: 1px solid var(--row-border); color: var(--text-secondary); background: var(--surface-subtle); font-size: clamp(9px, .65vw, 11px); font-weight: 750; letter-spacing: .025em; text-align: center; text-transform: uppercase; white-space: nowrap; }
.return-flow-page .return-inspection-table td { padding: 10px 7px; border-bottom: 1px solid var(--row-border); vertical-align: middle; }
.return-flow-page .return-inspection-section-row td { padding: 10px 14px !important; background: var(--surface-subtle); }
.return-flow-page .return-inspection-section-row--linen td { border-top: 0; }
.return-flow-page .return-inspection-section-row--non-linen td { border-top: 2px solid var(--border); }
.return-flow-page .return-inspection-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.return-flow-page .return-inspection-section-heading > div { min-width: 0; }
.return-flow-page .return-inspection-section-heading strong,
.return-flow-page .return-inspection-section-heading small { display: block; }
.return-flow-page .return-inspection-section-heading strong { color: var(--heading); font-size: 12px; font-weight: 750; letter-spacing: .02em; text-transform: uppercase; }
.return-flow-page .return-inspection-section-heading small { margin-top: 2px; color: var(--text-muted); font-size: 11px; line-height: 1.45; }
.return-flow-page .return-accounting-row.is-locked { background: color-mix(in srgb, var(--surface-subtle) 76%, transparent); }
.return-flow-page .return-accounting-row.is-locked input { cursor: not-allowed; opacity: .52; }
.return-flow-page .return-linen-locked-copy { margin-top: 5px !important; color: var(--warning) !important; font-weight: 650; }
.return-flow-page .return-inspection-table th:first-child,
.return-flow-page .return-inspection-table td:first-child { padding-left: 14px; text-align: left; }
.return-flow-page .return-item-cell strong,
.return-flow-page .return-item-cell small { display: block; }
.return-flow-page .return-item-cell strong { margin-bottom: 3px; color: var(--heading); font-size: 13px; line-height: 1.4; }
.return-flow-page .return-item-cell small { color: var(--text-muted); font-size: 11px; line-height: 1.5; }
.return-flow-page .return-inspection-table input[type="number"] { width: 100%; min-width: 0; min-height: 42px; padding: 9px 5px; border-color: var(--border); font-size: 13px; text-align: center; appearance: textfield; }
.return-flow-page .return-inspection-table input[type="number"]:focus,
.return-flow-page .return-action-footer textarea:focus { border-color: var(--interactive); }
.return-flow-page .return-inspection-table input[type="number"]::-webkit-inner-spin-button,
.return-flow-page .return-inspection-table input[type="number"]::-webkit-outer-spin-button { margin: 0; -webkit-appearance: none; }
.return-flow-page .return-accounted-total,
.return-flow-page .return-accounted-state { display: block; text-align: center; }
.return-flow-page .return-accounted-total { color: var(--heading); font-size: 13px; }
.return-flow-page .return-accounted-state { margin-top: 3px; color: var(--text-muted); font-size: 10px; line-height: 1.4; }
.return-flow-page .return-issue-details td { padding: 12px 14px; background: var(--surface-subtle); }
.return-flow-page .return-issue-details__grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(220px, .75fr); gap: 14px; }
.return-flow-page .return-action-area { display: grid; gap: 12px; }
.return-flow-page .return-action-footer { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; gap: 16px; }
.return-flow-page .return-action-footer label { min-width: 0; gap: 8px; color: var(--heading); font-size: 13px; font-weight: 700; }
.return-flow-page .return-remarks-input { position: relative; display: block; }
.return-flow-page .return-action-footer textarea { display: block; width: 100%; min-height: 96px; padding: 12px 14px; border-color: var(--border); font-size: 13px; resize: vertical; }
.return-flow-page .button.primary { color: #fff; border-color: #0863db; background: #0863db; font-size: 13px; font-weight: 700; }
.return-flow-page .button.primary:not(:disabled):hover,
.return-flow-page .button.primary:not(:disabled):focus-visible { color: #fff; border-color: #0452bc; background: #0452bc; }
.return-flow-page #record-return-button { justify-self: end; width: auto; max-width: 100%; min-height: 38px; margin: 0; padding: 8px 16px; font-size: 12px; }
.return-flow-page #record-return-button:disabled { cursor: not-allowed; opacity: .55; transform: none; box-shadow: none; }
.return-flow-page .return-empty-state { display: grid; min-height: 240px; place-content: center; }
.return-flow-page .return-history-scroll { max-height: 320px; overflow: auto; }

.return-flow-page .return-laundry-date-meta { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.return-flow-page .return-laundry-date-meta > small:first-child { flex: 1 1 240px; }
.return-flow-page .return-laundry-date-status { flex: 0 0 auto; font-weight: 700 !important; white-space: nowrap; }
.return-flow-page .return-laundry-date-status[data-state="ontime"] { color: var(--text-secondary); }
.return-flow-page .return-laundry-date-status[data-state="late"],
.return-flow-page .return-laundry-date-status[data-state="error"] { color: var(--warning); }
html[data-theme="dark"] .return-flow-page { --return-blue: #72b7f4; }
@media (max-width: 980px) {
    .return-flow-page .return-top-grid { grid-template-columns: minmax(0, 1fr); }
    .return-flow-page .return-summary-grid,
    .return-flow-page .return-top-grid.has-documents .return-summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
    .return-flow-page .return-summary-grid,
    .return-flow-page .return-action-footer,
    .return-flow-page .return-issue-details__grid { grid-template-columns: minmax(0, 1fr); }
    .return-flow-page .card { padding: 16px; }
    .return-flow-page .return-inspection-header,
    .return-flow-page .return-document-row,
    .return-flow-page .return-document-row-main,
    .return-flow-page .return-summary-note { flex-wrap: wrap; }
    .return-flow-page #record-return-button { justify-self: stretch; }
}
@media (prefers-reduced-motion: reduce) {
    .return-flow-page * { transition: none !important; }
}
</style>

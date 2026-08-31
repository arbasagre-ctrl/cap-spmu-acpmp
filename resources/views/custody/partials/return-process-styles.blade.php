<style>
/* Only the Action Officer's return workspace uses these presentation rules. */
.return-flow-page { --return-blue: #0863db; font-size: 13px; }
.return-flow-page [hidden] { display: none !important; }
.return-flow-page, .return-flow-page #return-primary { scroll-margin-top: 90px; }
.return-flow-page .return-workspace-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(250px, 25%); gap: 18px; align-items: stretch; }
.return-flow-page .return-primary-stack { display: grid; align-content: start; gap: 16px; min-width: 0; }
.return-flow-page .content-area, .return-flow-page .content-grid { margin: 0; }
.return-flow-page .card { min-width: 0; padding: 18px 20px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); box-shadow: none; }
/* Reset Bootstrap's gray header fill and inset padding within this workspace. */
.return-flow-page .card-header { display: flex; flex-direction: row; align-items: flex-start; justify-content: space-between; gap: 12px; margin: 0 0 12px; padding: 0; border: 0; border-radius: 0; background: transparent; }
.return-flow-page .eyebrow { margin: 0 0 4px; color: var(--text-secondary); font-size: 11px; font-weight: 750; line-height: 1.5; letter-spacing: .04em; }
.return-flow-page h2 { margin: 0; color: var(--heading); font-size: clamp(17px, 1.2vw, 20px); font-weight: 650; line-height: 1.4; }
.return-flow-page .notice { margin: 0; padding: 10px 14px; align-items: center; }
.return-flow-page .return-flash { gap: 12px; }
.return-flow-page .return-flash > div { flex: 1; min-width: 0; }
.return-flow-page .return-flash-dismiss { flex: 0 0 26px; width: 26px; height: 26px; padding: 0; }
.return-flow-page .return-flash-dismiss .ui-icon { display: block; width: 18px; height: 18px; }
.return-flow-page .return-context-grid { display: grid; grid-template-columns: minmax(0, .92fr) minmax(0, 1.08fr); gap: 18px; align-items: stretch; }
.return-flow-page .return-context-card .card-header { padding-bottom: 12px; border-bottom: 1px solid var(--row-border); }
.return-flow-page .detail-list { display: grid; grid-template-columns: minmax(120px, .9fr) minmax(0, 1.15fr); gap: 0; margin: 0; }
.return-flow-page .detail-list dt, .return-flow-page .detail-list dd { min-width: 0; margin: 0; padding: 9px 0; border-bottom: 1px solid var(--row-border); font-size: 13px; line-height: 1.5; overflow-wrap: anywhere; }
.return-flow-page .detail-list dt { display: flex; align-items: flex-start; gap: 9px; padding-right: 10px; color: var(--text-secondary); font-size: 12px; font-weight: 600; }
.return-flow-page .detail-list dd { color: var(--heading); }
.return-flow-page .detail-list dt:last-of-type, .return-flow-page .detail-list dd:last-of-type { border-bottom: 0; }
.return-flow-page .detail-list .ui-icon { flex-shrink: 0; width: 20px; height: 20px; color: var(--return-blue); }
.return-flow-page .return-document-list { display: grid; }
.return-flow-page .return-document-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--row-border); }
.return-flow-page .return-document-row:first-child { padding-top: 0; }
.return-flow-page .return-document-row:last-child { padding-bottom: 0; border-bottom: 0; }
.return-flow-page .return-document-copy { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
.return-flow-page .return-document-copy > .ui-icon { flex-shrink: 0; margin-top: 2px; color: var(--return-blue); }
.return-flow-page .return-document-copy strong, .return-flow-page .return-document-copy small { display: block; }
.return-flow-page .return-document-copy strong { color: var(--heading); font-size: 13px; }
.return-flow-page .return-document-copy small { margin-top: 2px; color: var(--text-muted); font-size: 13px; line-height: 1.4; }
.return-flow-page .return-document-row > .status-badge { flex-shrink: 0; font-size: 11px; white-space: nowrap; }
.return-flow-page .return-document-row > .button { flex-shrink: 0; min-height: 28px; padding: 5px 9px; font-size: 11px; }
.return-flow-page .return-inspection-card { display: grid; gap: 12px; }
.return-flow-page .return-inspection-header { margin-bottom: 0; align-items: center; }
.return-flow-page .return-outstanding-badge { padding: 6px 14px; color: var(--return-blue); border-color: var(--info-border); background: var(--info-bg); font-size: 12px; white-space: nowrap; }
.return-flow-page .return-linen-note { display: flex; align-items: center; gap: 12px; margin: 0; padding: 10px 14px; border-left-color: var(--return-blue); }
.return-flow-page .return-linen-note > .ui-icon { flex-shrink: 0; color: var(--return-blue); }
.return-flow-page .return-linen-note strong { color: var(--return-blue); font-size: 12px; }
.return-flow-page .return-linen-note p { margin: 2px 0 0; font-size: 13px; line-height: 1.5; }
.return-flow-page .return-inspection-scroll { min-width: 0; overflow-x: auto; overscroll-behavior-x: contain; border: 1px solid var(--border); border-radius: 7px; box-shadow: none; }
.return-flow-page .return-inspection-scroll table { width: 100%; min-width: 700px; margin: 0; table-layout: fixed; border-collapse: collapse; }
.return-flow-page .return-item-column { width: 29%; }
.return-flow-page .return-condition-column { width: 9.75%; }
.return-flow-page .return-total-column { width: 12.5%; }
.return-flow-page .return-inspection-table th { padding: 9px 5px; border-bottom: 1px solid var(--row-border); color: var(--text-secondary); background: var(--surface-subtle); font-size: clamp(9px, .65vw, 11px); font-weight: 750; letter-spacing: .025em; text-align: center; text-transform: uppercase; white-space: nowrap; }
.return-flow-page .return-inspection-table td { padding: 10px 7px; border-bottom: 1px solid var(--row-border); vertical-align: middle; }
.return-flow-page .return-inspection-table th:first-child, .return-flow-page .return-inspection-table td:first-child { padding-left: 14px; text-align: left; }
.return-flow-page .return-item-cell strong, .return-flow-page .return-item-cell small { display: block; }
.return-flow-page .return-item-cell strong { margin-bottom: 3px; color: var(--heading); font-size: 13px; line-height: 1.4; }
.return-flow-page .return-item-cell small { color: var(--text-muted); font-size: 11px; line-height: 1.5; }
.return-flow-page .return-inspection-table input[type="number"] { width: 100%; min-width: 0; min-height: 42px; padding: 9px 5px; border-color: var(--border); font-size: 13px; text-align: center; appearance: textfield; }
.return-flow-page .return-inspection-table input[type="number"]:focus, .return-flow-page .return-action-footer textarea:focus { border-color: var(--interactive); }
.return-flow-page .return-inspection-table input[type="number"]::-webkit-inner-spin-button,
.return-flow-page .return-inspection-table input[type="number"]::-webkit-outer-spin-button { margin: 0; -webkit-appearance: none; }
.return-flow-page .return-accounted-total, .return-flow-page .return-accounted-state { display: block; text-align: center; }
.return-flow-page .return-accounted-total { color: var(--heading); font-size: 13px; }
.return-flow-page .return-accounted-state { margin-top: 3px; color: var(--text-muted); font-size: 10px; line-height: 1.4; }
.return-flow-page .return-issue-details td { padding: 12px 14px; background: var(--surface-subtle); }
.return-flow-page .return-issue-details__grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(220px, .75fr); gap: 14px; }
.return-flow-page .return-action-area { display: grid; gap: 12px; }
.return-flow-page .return-accounting-message { display: flex; align-items: center; gap: 10px; margin: 0; padding: 6px 12px; border-left-width: 1px; font-size: 11px; line-height: 1.5; }
.return-flow-page .return-accounting-message > .ui-icon { flex-shrink: 0; }
.return-flow-page .callout.success { color: var(--success); border-color: var(--success-border); background: var(--success-bg); }
.return-flow-page .return-action-footer { display: grid; grid-template-columns: minmax(0, 1fr); align-items: start; gap: 16px; }
.return-flow-page .return-action-footer label { min-width: 0; gap: 8px; color: var(--heading); font-size: 13px; font-weight: 700; }
.return-flow-page .return-remarks-input { position: relative; display: block; }
.return-flow-page .return-action-footer textarea { display: block; width: 100%; min-height: 110px; height: clamp(120px, 10vw, 175px); padding: 12px 14px 32px; border-color: var(--border); font-size: 13px; resize: vertical; }
.return-flow-page .return-remarks-counter { position: absolute; right: 14px; bottom: 8px; color: var(--text-muted); font-size: 11px; font-weight: 400; pointer-events: none; }
.return-flow-page .button.primary { color: #fff; border-color: #0863db; background: #0863db; font-size: 13px; font-weight: 700; }
.return-flow-page .button.primary:not(:disabled):hover, .return-flow-page .button.primary:not(:disabled):focus-visible { color: #fff; border-color: #0452bc; background: #0452bc; }
.return-flow-page #record-return-button { justify-self: end; width: auto; max-width: 100%; min-height: 38px; margin: 0; padding: 8px 16px; font-size: 12px; }
.return-flow-page #record-return-button:disabled { cursor: not-allowed; opacity: .55; transform: none; box-shadow: none; }
.return-flow-page .return-status-card { display: flex; flex-direction: column; align-self: stretch; position: static; padding: 0; }
.return-flow-page .return-status-card > .card-header { display: block; margin: 0; padding: 24px 20px 16px; border-bottom: 1px solid var(--row-border); }
.return-flow-page .return-status-title { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.return-flow-page .return-status-title h2 { font-size: 17px; }
.return-flow-page .return-status-title .status-badge { flex-shrink: 0; font-size: 11px; }
.return-flow-page .return-status-scroll { padding: 8px 18px 20px; }
.return-flow-page .return-status-scroll .detail-list { grid-template-columns: minmax(100px, .95fr) minmax(0, 1fr); }
.return-flow-page .return-status-scroll .detail-list dt, .return-flow-page .return-status-scroll .detail-list dd { padding-top: 12px; padding-bottom: 12px; }
.return-flow-page .return-next-callout { display: flex; align-items: flex-start; gap: 12px; margin: 28px 0 18px; padding: 18px 15px; border-left-width: 1px; }
.return-flow-page .return-next-callout > .ui-icon { flex-shrink: 0; margin-top: 2px; }
.return-flow-page .return-next-callout strong { font-size: 13px; }
.return-flow-page .return-next-callout p { margin: 8px 0 0; font-size: 13px; line-height: 1.55; }
.return-flow-page .return-status-scroll > .button { width: 100%; min-height: 48px; margin-top: 4px; }
.return-flow-page .return-status-scroll > .button + .button { margin-top: 10px; }
.return-flow-page .return-empty-state { display: grid; min-height: 240px; place-content: center; }
.return-flow-page .return-history-section { margin-top: 18px; }
.return-flow-page .return-history-scroll { max-height: 320px; overflow: auto; }
html[data-theme="dark"] .return-flow-page { --return-blue: #72b7f4; }
@media (max-width: 1180px) {
    .return-flow-page .return-workspace-grid { grid-template-columns: minmax(0, 1fr); }
    .return-flow-page .return-status-scroll .detail-list { grid-template-columns: minmax(160px, .4fr) minmax(0, 1fr); }
}
@media (max-width: 760px) {
    .return-flow-page .return-context-grid, .return-flow-page .return-action-footer, .return-flow-page .return-issue-details__grid { grid-template-columns: minmax(0, 1fr); }
    .return-flow-page .card { padding: 16px; }
    .return-flow-page .return-status-card { padding: 0; }
    .return-flow-page .return-inspection-header { flex-wrap: wrap; }
    .return-flow-page .return-document-row { flex-wrap: wrap; }
    .return-flow-page .return-document-row > .button { width: auto; }
}
@media (prefers-reduced-motion: reduce) {
    .return-flow-page * { transition: none !important; }
}
</style>

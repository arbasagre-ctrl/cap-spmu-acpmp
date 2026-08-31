<style>
.laundry-detail {
    --laundry-detail-blue: #0866df;
    --laundry-detail-soft: #edf5ff;
    display: grid;
    gap: 20px;
    min-width: 0;
    color: var(--text);
    font-size: 13px;
}
.laundry-detail .content-area, .laundry-detail .content-grid { min-width: 0; margin: 0; }
.laundry-detail .laundry-detail-heading { margin: 0; }
.laundry-detail .laundry-detail-heading .eyebrow { margin-bottom: 10px; font-size: 11px; }
.laundry-detail .laundry-detail-heading h1 { margin: 0 0 8px; font-size: clamp(26px, 2.05vw, 32px); line-height: 1.2; }
.laundry-detail .laundry-detail-heading p:not(.eyebrow) { font-size: 14px; }
.laundry-detail .card { min-width: 0; padding: 22px 24px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.laundry-detail .card-header { display: flex; flex-direction: row; justify-content: space-between; align-items: flex-start; gap: 14px; margin: 0 0 18px; padding: 0; border: 0; border-radius: 0; background: transparent; }
.laundry-detail h2 { margin: 0; color: var(--heading); font-size: 16px; font-weight: 700; line-height: 1.4; }
.laundry-detail .eyebrow { font-size: 11px; }
.laundry-detail .laundry-operation-grid { grid-template-columns: minmax(0, .95fr) minmax(0, 1.05fr); align-items: stretch; gap: 20px; }

/* The three milestones stay visible; only the active milestone has a card fill. */
.laundry-detail .laundry-progress-card .card-header { align-items: flex-start; margin-bottom: 24px; }
.laundry-detail .laundry-progress-card h2 { margin-top: 6px; font-size: 19px; }
.laundry-detail .laundry-progress-status { flex-shrink: 0; padding: 6px 12px; font-size: 12px; }
.laundry-detail .laundry-progress-rail { --laundry-step-gap: clamp(24px, 4.5vw, 90px); display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--laundry-step-gap); margin: 0; padding: 0; list-style: none; }
.laundry-detail .laundry-progress-step { position: relative; display: grid; grid-template-columns: 48px minmax(0, 1fr); align-items: center; gap: 16px; min-height: 76px; padding: 10px 14px; border: 1px solid transparent; border-radius: 10px; background: transparent; opacity: 1; }
.laundry-detail .laundry-progress-step:not(:last-child)::after { content: ""; position: absolute; top: 50%; left: calc(100% + 1px); width: calc(var(--laundry-step-gap) - 10px); height: 1px; background: var(--border-strong); }
.laundry-detail .laundry-progress-marker { display: grid; place-items: center; width: 48px; height: 48px; border: 1px solid var(--border-strong); border-radius: 50%; color: var(--text-muted); background: var(--surface-elevated); }
.laundry-detail .laundry-progress-marker .ui-icon { width: 25px; height: 25px; }
.laundry-detail .laundry-progress-step strong { color: var(--text-secondary); font-size: 13px; font-weight: 700; line-height: 1.45; }
.laundry-detail .laundry-progress-content .workflow-tracker__meta { margin-top: 5px; color: var(--text-muted); font-size: 11px; font-weight: 700; letter-spacing: .025em; text-transform: uppercase; }
.laundry-detail .laundry-progress-step.is-current { border-color: var(--laundry-detail-blue); background: var(--laundry-detail-soft); }
.laundry-detail .laundry-progress-step.is-current .laundry-progress-marker { color: #fff; background: #0866df; border-color: #0866df; }
.laundry-detail .laundry-progress-step.is-current strong { color: var(--heading); }
.laundry-detail .laundry-progress-step.is-current .workflow-tracker__meta { color: var(--laundry-detail-blue); }
.laundry-detail .laundry-progress-step.is-current::after { background: var(--laundry-detail-blue); }
.laundry-detail .laundry-progress-step.is-complete strong { color: var(--heading); }
.laundry-detail .laundry-progress-step.is-complete .workflow-tracker__meta { color: var(--success); }

.laundry-detail .laundry-detail-card-title { align-items: center; justify-content: flex-start; gap: 14px; margin-bottom: 16px; }
.laundry-detail .laundry-detail-card-title > .ui-icon { flex-shrink: 0; color: var(--laundry-detail-blue); }
.laundry-detail .laundry-form-description { margin: 0 0 16px; color: var(--text-secondary); font-size: 13px; line-height: 1.6; }
.laundry-detail .laundry-signature-list { display: grid; gap: 8px; margin: 0; }
.laundry-detail .laundry-signature-list > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 40px; padding: 7px 10px; border: 1px solid var(--border); border-radius: 8px; }
.laundry-detail .laundry-signature-list dt { display: flex; align-items: center; gap: 14px; margin: 0; color: var(--text-secondary); font-size: 13px; font-weight: 400; }
.laundry-detail .laundry-signature-list dt .ui-icon { flex-shrink: 0; color: var(--laundry-detail-blue); }
.laundry-detail .laundry-signature-list dd { flex-shrink: 0; margin: 0; }
.laundry-detail .laundry-signature-list .status-badge { font-size: 11px; }
.laundry-detail .laundry-form-actions { margin-top: 18px; }
.laundry-detail .laundry-linen-facts { display: grid; grid-template-columns: minmax(165px, 1fr) minmax(0, 1.1fr); margin: 0; }
.laundry-detail .laundry-linen-facts dt, .laundry-detail .laundry-linen-facts dd { min-width: 0; margin: 0; padding: 8px 0; border-bottom: 1px solid var(--row-border); line-height: 1.5; overflow-wrap: anywhere; }
.laundry-detail .laundry-linen-facts dt { padding-right: 12px; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.laundry-detail .laundry-linen-facts dd { color: var(--text); font-size: 13px; }
.laundry-detail .laundry-linen-facts dt:last-of-type, .laundry-detail .laundry-linen-facts dd:last-of-type { border-bottom: 0; }

.laundry-detail .button { width: auto; min-height: 40px; padding: 9px 16px; border-radius: 8px; font-size: 12px; }
.laundry-detail .button.primary { color: #fff; border-color: #0866df; background: #0866df; }
.laundry-detail .button.primary:hover { color: #fff; border-color: #0452bc; background: #0452bc; }
.laundry-detail .button.secondary { color: var(--laundry-detail-blue); border-color: var(--laundry-detail-blue); background: transparent; }
.laundry-detail .button.secondary:hover { background: var(--laundry-detail-soft); }
.laundry-detail .laundry-next-action { display: grid; grid-template-columns: 36px minmax(0, 1fr) auto; align-items: center; gap: 20px; padding-top: 24px; padding-bottom: 24px; }
.laundry-detail .laundry-next-action > .ui-icon { width: 36px; height: 36px; color: var(--laundry-detail-blue); }
.laundry-detail .laundry-next-action p { margin: 8px 0 0; color: var(--text-secondary); font-size: 13px; line-height: 1.55; }
.laundry-detail .laundry-next-action .button { min-height: 46px; padding: 12px 20px; white-space: nowrap; }
.laundry-detail .form-grid > .button { justify-self: start; }
.laundry-detail .check-row { display: flex; align-items: flex-start; gap: 10px; }
.laundry-detail .check-row input { flex-shrink: 0; margin-top: 2px; }
.laundry-detail .callout.success { color: var(--success); border-color: var(--success-border); background: var(--success-bg); }
.laundry-detail .table-wrap { min-width: 0; }

html[data-theme="dark"] .laundry-detail { --laundry-detail-blue: #72b7f4; --laundry-detail-soft: #132b40; }
@media (max-width: 1050px) {
    .laundry-detail .laundry-operation-grid { grid-template-columns: minmax(0, 1fr); }
}
@media (max-width: 900px) {
    .laundry-detail .laundry-progress-rail { grid-template-columns: minmax(0, 1fr); gap: 20px; }
    .laundry-detail .laundry-progress-step:not(:last-child)::after { top: 100%; left: 37px; width: 1px; height: 21px; }
    .laundry-detail .laundry-progress-card .card-header { flex-wrap: wrap; }
    .laundry-detail .laundry-next-action { grid-template-columns: 32px minmax(0, 1fr); gap: 14px; }
    .laundry-detail .laundry-next-action .button { grid-column: 2; justify-self: start; }
}
@media (max-width: 560px) {
    .laundry-detail { gap: 16px; }
    .laundry-detail .card { padding: 18px 16px; }
    .laundry-detail .laundry-linen-facts { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .laundry-detail .laundry-next-action .button { grid-column: 1 / -1; width: 100%; }
    .laundry-detail .card-header { flex-wrap: wrap; }
}
</style>

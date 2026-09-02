<style>
/* Release-only scope: borrower records, return inspection and oversight stay unchanged. */
.release-flow-page {
    --release-blue: #0863db;
    --release-green: #08a536;
    --release-actions-width: 152px;
    font-size: 13px;
}
.release-flow-page [hidden] { display: none !important; }
.release-flow-page .release-page-heading {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(330px, .95fr);
    align-items: start;
    gap: 24px;
    margin-bottom: 18px;
}
.release-flow-page .release-page-heading .release-eyebrow {
    margin: 2px 0 8px;
    color: var(--release-blue);
    font-size: 11px;
    font-weight: 750;
}
.release-flow-page .release-page-heading h1 {
    margin: 0 0 8px;
    font-size: clamp(26px, 2.65vw, 34px);
    font-weight: 750;
    overflow-wrap: anywhere;
}
.release-flow-page .release-page-heading > div > p:last-child { font-size: 14px; }
.release-flow-page .release-status-summary {
    overflow: hidden;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
}
.release-status-heading {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 14px 16px;
    border-bottom: 1px solid var(--row-border);
}
.release-status-heading > .ui-icon { flex-shrink: 0; margin-top: 1px; }
.release-status-heading > div { display: grid; gap: 2px; }
.release-status-heading strong { font-size: 13px; font-weight: 750; }
.release-status-heading span { color: var(--text-muted); font-size: 12px; }
.release-status-heading--success { color: #078f2c; background: color-mix(in srgb, var(--success-bg) 35%, var(--surface-elevated)); }
.release-status-heading--info { color: var(--info); background: var(--info-bg); }
.release-status-heading--warning { color: var(--warning); background: var(--warning-bg); }
.release-status-dates {
    display: grid;
    grid-template-columns: minmax(0, 1.7fr) minmax(95px, 1fr);
    padding: 13px 16px;
}
.release-status-dates > div { display: flex; align-items: flex-start; gap: 8px; min-width: 0; }
.release-status-dates > div + div { margin-left: 12px; padding-left: 14px; border-left: 1px solid var(--row-border); }
.release-status-dates .ui-icon { flex-shrink: 0; color: #397fe4; margin-top: 2px; }
.release-status-dates strong, .release-status-dates span { display: block; color: var(--heading); font-size: 11px; line-height: 1.5; }
.release-status-dates span { font-weight: 600; }
.release-flow-page .request-tracker-card { margin-top: 0; }
.release-flow-page .request-tracker { padding: 16px 10px; }
.release-flow-page .request-tracker__scroll { padding: 5px 0 7px; }
.release-flow-page .request-tracker__rail { min-width: 740px; grid-template-columns: repeat(8, minmax(0, 1fr)); }
.release-flow-page .request-tracker__step { padding: 0 4px; }
.release-flow-page .request-tracker__marker { width: 36px; height: 36px; margin-bottom: 12px; }
.release-flow-page .request-tracker__marker > svg { width: 20px; height: 20px; }
.release-flow-page .request-tracker__step::after { top: 18px; left: calc(50% + 22px); width: calc(100% - 44px); height: 2px; }
.release-flow-page .request-tracker__step.is-complete::after { background: var(--release-green); }
.release-flow-page .request-tracker__step.is-complete .request-tracker__marker { background: linear-gradient(180deg, #0aae42, #069329); border-color: #099c32; color: #fff; }
.release-flow-page .request-tracker__step.is-current .request-tracker__marker { background: var(--release-blue); border-color: var(--release-blue); color: #fff; box-shadow: 0 0 0 4px var(--surface-elevated), 0 0 0 6px var(--info-bg); }
.release-flow-page .request-tracker__step.is-current .request-tracker__copy strong { color: var(--release-blue); }
.release-flow-page .request-tracker__copy strong { max-width: 90px; font-size: 12px; line-height: 1.5; }
.release-flow-page .request-tracker__copy time,
.release-flow-page .request-tracker__pending-label { display: none; }
.release-flow-page .request-tracker__step.is-pending .request-tracker__copy { opacity: 1; }
.release-flow-page .request-tracker__step.is-pending .request-tracker__copy strong { color: var(--text-muted); }
.release-flow-page .content-area, .release-flow-page .content-grid { margin-top: 15px; }
.release-flow-page .release-context-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: stretch; }
.release-flow-page .release-context-card { padding: 18px 16px 12px; }
.release-flow-page .release-card-title { display: flex; align-items: center; gap: 9px; margin-bottom: 13px; }
.release-card-title > .ui-icon { flex-shrink: 0; color: #5388ce; }
.release-flow-page .release-card-title h2,
.release-flow-page #release-process-title { margin: 0; font-size: 13px; font-weight: 800; text-transform: uppercase; line-height: 1.4; }
.release-flow-page .release-context-card .release-card-title { padding: 0 10px 13px; border-bottom: 1px solid var(--row-border); }
.release-flow-page .release-context-list { grid-template-columns: minmax(125px, .95fr) minmax(0, 1fr); margin: 0 10px; }
.release-flow-page .release-context-list dt,
.release-flow-page .release-context-list dd { padding: 8px 0; border-bottom: 1px solid var(--row-border); font-size: 12px; line-height: 1.5; overflow-wrap: anywhere; }
.release-flow-page .release-context-list dt { padding-right: 10px; }
.release-flow-page .release-context-list dt:last-of-type,
.release-flow-page .release-context-list dd:last-of-type { border-bottom: 0; }
.release-flow-page .release-approved-card { padding: 17px 15px 15px; }
.release-flow-page .table-wrap { overflow-x: auto; box-shadow: none; }
.release-flow-page .release-approved-table { min-width: 580px; }
.release-flow-page .release-approved-table th { font-size: 11px; letter-spacing: 0; padding: 8px 13px; }
.release-flow-page .release-approved-table td { padding: 10px 13px; font-size: 13px; line-height: 1.4; }
.release-flow-page .release-approved-table th:first-child { width: 40%; }
.release-flow-page .release-approved-table th:not(:first-child),
.release-flow-page .release-approved-table td:not(:first-child) { text-align: center; }
.release-approved-table td > strong, .release-approved-table td > small { display: block; }
.release-approved-table td > strong { font-size: 12px; color: var(--heading); }
.release-approved-table td > small { margin-top: 2px; font-size: 11px; }
.release-flow-page .release-process-card { padding: 18px 15px 15px; }
.release-flow-page #release-process-title { margin: 0 3px 13px; }
.release-process-steps {
    margin: 0;
    padding: 0 14px 0 48px;
    list-style: none;
    border: 1px solid var(--border);
    border-radius: 8px;
    box-shadow: var(--shadow-sm);
}
.release-process-step { position: relative; padding: 15px 0; border-bottom: 1px solid var(--row-border); scroll-margin-top: 90px; }
.release-process-step:last-child { border-bottom: 0; }
.release-process-step:not(:last-child)::before { content: ""; position: absolute; top: 36px; left: -33px; bottom: -16px; width: 2px; background: var(--row-border); }
.release-step-number { position: absolute; top: 16px; left: -44px; display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: var(--surface-muted); color: var(--text-muted); font-size: 12px; font-weight: 750; z-index: 1; }
.release-process-step.is-complete > .release-step-number { color: #fff; background: #079e32; }
.release-process-step.is-current > .release-step-number { color: #fff; background: var(--release-blue); }
.release-step-heading { display: grid; grid-template-columns: minmax(0, 1fr) 116px var(--release-actions-width); align-items: start; column-gap: 12px; }
.release-flow-page .release-step-copy h3 { margin: 0; color: var(--heading); font-size: 15px; font-weight: 750; line-height: 1.5; }
.release-step-copy p { margin: 4px 0 0; color: var(--text-muted); font-size: 12px; line-height: 1.5; }
.release-step-schedule, .release-step-notified { display: flex; align-items: flex-start; gap: 7px; }
.release-step-copy .ui-icon { flex-shrink: 0; margin-top: 1px; }
.release-step-notified .ui-icon { color: #079e32; }
.release-step-badge { justify-self: center; margin-top: 1px; padding: 3px 10px; border-radius: 999px; font-size: 10px; font-weight: 750; line-height: 1.4; text-align: center; }
.release-step-badge.is-complete { color: var(--success); background: var(--success-bg); }
.release-step-badge.is-pending { color: var(--text-muted); background: var(--surface-muted); }
.release-schedule-status { display: flex; flex-direction: column; align-items: center; justify-content: space-between; align-self: stretch; gap: 8px; }
.release-flow-page .release-schedule-edit { display: inline-flex; align-items: center; gap: 6px; margin: 0; padding: 0; border: 0; background: transparent; color: var(--release-blue); font-size: 11.5px; font-weight: 700; line-height: 1.2; white-space: nowrap; cursor: pointer; }
.release-flow-page .release-schedule-edit > .ui-icon { flex-shrink: 0; }
.release-flow-page .release-schedule-edit:hover, .release-flow-page .release-schedule-edit:focus-visible { color: var(--interactive-hover); text-decoration: underline; }
.release-step-actions { display: flex; align-items: center; justify-content: flex-end; gap: 12px; min-height: 30px; }
.release-schedule-actions { padding-top: 18px; }
.release-flow-page .button.release-outline { display: inline-flex; align-items: center; justify-content: center; min-height: 30px; padding: 5px 12px; border: 1px solid var(--release-blue); border-radius: 6px; color: var(--release-blue); background: var(--surface-elevated); font-size: 11px; font-weight: 700; white-space: nowrap; }
.release-flow-page .button.ui-pressable.release-outline:not(:disabled):hover,
.release-flow-page .button.ui-pressable.release-outline:not(:disabled):focus-visible { color: #fff !important; background: #0863db !important; border-color: #0863db !important; }
.release-flow-page button.release-step-toggle { display: grid; place-items: center; flex: 0 0 32px; width: 32px; height: 30px; min-height: 30px; padding: 0; border: 1px solid transparent; border-radius: 6px; color: var(--text-muted); background: transparent; box-shadow: none; cursor: pointer; }
.release-flow-page button.release-schedule-toggle { border-color: var(--border); background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.release-flow-page button.release-step-toggle:hover,
.release-flow-page button.release-step-toggle:focus-visible { color: var(--release-blue); border-color: var(--release-blue); background: var(--surface-hover); }
.release-step-toggle .ui-icon { display: block; width: 18px; height: 18px; flex-shrink: 0; stroke-width: 2; }
.release-step-panel { margin-top: 12px; }
.release-flow-page .release-schedule-fields { margin-top: 0; gap: 16px; }
.release-flow-page .release-schedule-form, .release-flow-page .release-preparation-form { margin: 0; }
.release-flow-page .release-preparation-form > p { margin: 0; color: var(--text-muted); font-size: 12px; }
.release-flow-page .release-preparation-form .table-wrap table { min-width: 580px; }
.prepared-quantity-stepper {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 36px;
    align-items: stretch;
    width: 100%;
    max-width: 190px;
    background: var(--input-bg);
    border: 1px solid var(--border-strong);
    border-radius: 7px;
    transition: border-color var(--motion) ease, box-shadow var(--motion) ease;
}
.prepared-quantity-stepper:focus-within { border-color: var(--interactive); box-shadow: var(--focus-ring); }
.release-preparation-form .prepared-quantity-stepper [data-prepared-quantity] {
    max-width: none;
    min-height: 38px;
    margin: 0;
    padding: 8px 2px;
    background: transparent;
    border: 0;
    border-radius: 0;
    text-align: center;
}
.release-preparation-form .prepared-quantity-stepper [data-prepared-quantity]:focus { outline: 0; box-shadow: none; }
/* Actual Prepared only: the native spinner is replaced by the click-once steppers. */
input.actual-prepared-quantity::-webkit-outer-spin-button,
input.actual-prepared-quantity::-webkit-inner-spin-button { margin: 0; -webkit-appearance: none; }
input.actual-prepared-quantity { -moz-appearance: textfield; appearance: textfield; }
.prepared-quantity-step {
    display: grid;
    place-items: center;
    min-height: 38px;
    padding: 0;
    color: var(--text-secondary);
    background: transparent;
    border: 0;
    border-radius: 6px;
    font-size: 15px;
    font-weight: 800;
    line-height: 1;
    cursor: pointer;
    transition: color var(--motion-fast) ease, background-color var(--motion-fast) ease;
}
.prepared-quantity-step:hover:not(:disabled) { color: var(--interactive); background: var(--surface-hover); }
.prepared-quantity-step:disabled { color: var(--text-soft); cursor: not-allowed; }
.release-flow-page .release-preparation-form .empty-state { min-height: 0; padding: 12px; align-items: flex-start; text-align: left; }
.release-form-actions { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding-right: 40px; }
.release-documents-panel { margin-top: 4px; }
.release-document-row { display: grid; grid-template-columns: minmax(0, 1fr) var(--release-actions-width); align-items: center; gap: 12px; padding: 4px 0; }
.release-document-copy { display: grid; grid-template-columns: 20px minmax(130px, .52fr) minmax(0, 1fr); align-items: center; gap: 9px; }
.release-document-copy > .ui-icon { color: #5388ce; }
.release-document-copy > strong { font-size: 12px; color: var(--heading); }
.release-document-copy > small { font-size: 12px; text-transform: none; }
.release-document-row > .button { justify-self: end; margin-right: 74px; min-width: 56px; }
.release-flow-page .release-step-note { margin: 8px 0 0; color: var(--text-muted); font-size: 12px; }
.release-flow-page .release-physical-heading h3 { margin: 0; color: var(--text-muted); font-size: 15px; font-weight: 750; line-height: 1.5; text-transform: uppercase; }
.release-flow-page .release-handover-form { margin-top: 26px; gap: 26px; }
.release-flow-page .release-handover-intro { margin: 0; font-size: 12px; line-height: 1.6; color: var(--text-muted); }
.release-flow-page .release-handover-form .checkbox { align-items: flex-start; gap: 18px; margin: 0; font-size: 12px; font-weight: 400; line-height: 1.5; color: var(--heading); }
.release-handover-form .checkbox > span { max-width: 960px; }
.release-handover-form input[type="checkbox"] { flex: 0 0 14px; width: 14px; height: 14px; min-height: 14px; margin-top: 2px; accent-color: var(--release-blue); }
.release-handover-footer { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
.release-flow-page .release-handover-footer label { gap: 8px; color: var(--heading); font-size: 12px; font-weight: 700; line-height: 1.4; }
.release-flow-page .release-handover-footer textarea { min-height: 100px; padding: 12px 14px; border-color: var(--border); resize: vertical; font-size: 12px; }
.release-flow-page .release-handover-footer textarea:focus { border-color: var(--release-blue); }
.release-flow-page #confirm-physical-release-button { justify-self: stretch; width: 100%; min-height: 46px; margin-top: 0; padding: 10px 16px; border-radius: 7px; color: #fff; border-color: #0863db; background: #0863db; font-size: 12px; }
.release-flow-page #confirm-physical-release-button:disabled { color: var(--text-soft); border-color: var(--border); background: var(--surface-elevated); opacity: 1; box-shadow: none; transform: none; }
.release-flow-page .button.release-primary { display: inline-flex; align-items: center; justify-content: center; gap: 9px; justify-self: end; min-height: 37px; padding: 8px 14px; border-radius: 6px; font-size: 12px; font-weight: 700; }
.release-flow-page .button.ui-pressable.release-primary:not(:disabled) { border-color: #0863db; background: #0863db; color: #fff; cursor: pointer; }
.release-flow-page .button.ui-pressable.release-primary:not(:disabled):hover,
.release-flow-page .button.ui-pressable.release-primary:not(:disabled):focus-visible { border-color: #0452bc !important; background: #0452bc !important; color: #fff !important; }
.release-flow-page .button.release-primary .ui-icon { color: inherit; flex-shrink: 0; }
.release-flow-page .release-window-notice { margin-top: 10px; }
.release-flow-page #physical-release-availability { margin-bottom: 12px; }
.release-process-note { display: flex; align-items: flex-start; gap: 11px; margin-top: 15px; padding: 12px 14px; border: 1px solid var(--info-border); border-radius: 7px; color: var(--info); background: var(--info-bg); font-size: 12px; line-height: 1.5; }
.release-process-note > .ui-icon { flex-shrink: 0; margin-top: 1px; color: var(--release-blue); }
html[data-theme="dark"] .release-flow-page { --release-blue: #72b7f4; }
html[data-theme="dark"] .release-status-heading--success { color: var(--success); }
html[data-theme="dark"] .release-flow-page .request-tracker__step.is-current .request-tracker__marker,
html[data-theme="dark"] .release-process-step.is-current > .release-step-number { background: #0863db; border-color: #0863db; }
@media (max-width: 1100px) {
    .release-flow-page .release-page-heading { grid-template-columns: 1fr; }
    .release-flow-page .release-status-summary { width: 100%; max-width: 540px; justify-self: end; }
    .release-flow-page { --release-actions-width: 140px; }
}
@media (max-width: 850px) {
    .release-flow-page .release-context-grid { grid-template-columns: minmax(0, 1fr); }
    .release-flow-page .release-status-summary { max-width: none; }
    .release-document-copy { grid-template-columns: 20px minmax(0, 1fr); }
    .release-document-copy > small { grid-column: 2; }
}
@media (max-width: 640px) {
    .release-process-steps { padding-left: 37px; padding-right: 12px; }
    .release-step-number { left: -31px; width: 22px; height: 22px; }
    .release-process-step:not(:last-child)::before { left: -21px; }
    .release-step-heading { grid-template-columns: minmax(0, 1fr) auto; gap: 10px; }
    .release-step-copy { grid-column: 1; grid-row: 1; }
    .release-step-badge { grid-column: 1; grid-row: 2; justify-self: start; }
    .release-schedule-status { grid-column: 1; grid-row: 2; align-self: start; align-items: flex-start; justify-content: flex-start; }
    .release-step-actions { grid-column: 2; grid-row: 1; }
    .release-schedule-actions { grid-column: 1 / -1; grid-row: 3; padding-top: 0; }
    .release-schedule-actions > .button { width: auto; }
    .release-document-row { grid-template-columns: minmax(0, 1fr) auto; }
    .release-document-row > .button { margin-right: 0; }
    .release-flow-page .release-schedule-fields { grid-template-columns: minmax(0, 1fr); }
    .release-flow-page .release-handover-form .checkbox { gap: 12px; }
    .release-form-actions { padding-right: 0; flex-wrap: wrap; }
    .release-flow-page .release-context-list { grid-template-columns: minmax(120px, .95fr) minmax(0, 1fr); margin: 0; }
    .release-status-dates { grid-template-columns: minmax(0, 1.5fr) minmax(90px, 1fr); padding: 12px; }
}
@media (prefers-reduced-motion: reduce) {
    .release-flow-page *, .release-flow-page *::after { transition: none !important; }
}
</style>

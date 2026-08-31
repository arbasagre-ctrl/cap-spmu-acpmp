<style>
/* Form content only: the existing request stepper keeps its markup and styling. */
.create-request-heading h1 { font-size: clamp(26px, 2.2vw, 32px); font-weight: 750; }
.create-request-heading .heading-copy { font-size: 14px; color: var(--text-muted); }
#request-form .create-request-ui .request-details-card,
#request-form .create-request-ui .request-picker-card { border: 1px solid var(--border); border-radius: 12px; background: var(--surface-elevated); box-shadow: 0 2px 7px rgba(16, 42, 67, .055); }
#request-form .create-request-ui .request-details-card .request-card-header,
#request-form .create-request-ui .request-picker-card .request-card-header { padding: 25px 28px 20px; background: transparent; border: 0; }
#request-form .create-request-ui .request-details-card .request-card-header .eyebrow,
#request-form .create-request-ui .request-picker-card .request-card-header .eyebrow,
#request-form .create-request-ui .request-schedule-fields > .eyebrow { margin: 0 0 7px; color: #065df0; font-size: 12px; font-weight: 750; letter-spacing: 0; line-height: 1.4; }
#request-form .create-request-ui .request-details-card .request-card-header h2,
#request-form .create-request-ui .request-picker-card .request-card-header h2 { color: var(--heading); font-size: 18px; font-weight: 700; line-height: 1.4; }
#request-form .create-request-ui .request-details-card .field-grid { gap: 25px 32px; }
#request-form .create-request-ui .request-details-card .field-grid > label { margin: 0; color: var(--text-secondary); font-size: 14px; font-weight: 650; line-height: 1.5; }
#request-form .create-request-ui .request-details-card .field-grid input,
#request-form .create-request-ui .request-details-card .field-grid select { display: block; width: 100%; height: 48px; min-height: 48px; margin-top: 10px; padding: 11px 15px; border: 1px solid var(--border); border-radius: 7px; background-color: var(--surface-elevated); color: var(--heading); font-size: 14px; font-weight: 400; }
#request-form .create-request-ui .request-details-card .field-grid select { padding-right: 36px; }
#request-form .create-request-ui .request-details-card input:focus,
#request-form .create-request-ui .request-details-card select:focus,
#request-form .create-request-ui .request-picker-card input:focus { border-color: #065df0; outline: 0; box-shadow: 0 0 0 3px rgba(6, 93, 240, .12); }
#request-form .create-request-ui .request-details-card .request-information-fields { padding: 0 28px 28px; }
#request-form .create-request-ui .request-schedule-fields { padding: 24px 28px 28px; border-top: 1px solid var(--border); }
#request-form .create-request-ui .request-schedule-fields > .eyebrow { margin-bottom: 18px; }
#request-form .create-request-ui .request-details-card .student-activity-panel { margin: 0; padding: 22px 28px 26px; border: 0; border-top: 1px solid var(--border); border-radius: 0; background: transparent; }
#request-form .create-request-ui .student-activity-panel .checkbox { align-items: flex-start; gap: 17px; cursor: pointer; }
#request-form .create-request-ui .student-activity-panel .checkbox input[type="checkbox"] { width: 22px; height: 22px; min-height: 22px; margin: 0; accent-color: #065df0; }
#request-form .create-request-ui .student-activity-panel .checkbox > span { display: grid; gap: 5px; min-width: 0; }
#request-form .create-request-ui .student-activity-panel strong { color: var(--text-secondary); font-size: 14px; font-weight: 650; line-height: 1.5; }
#request-form .create-request-ui .student-activity-panel small { display: block; color: var(--text-muted); font-size: 13px; font-weight: 400; line-height: 1.5; }
#request-form .create-request-ui .request-details-actions { padding: 21px 24px; gap: 14px; margin: 0; border: 0; border-top: 1px solid var(--border); border-radius: 0; background: transparent; }
#request-form .create-request-ui .stage-actions .button { min-height: 46px; padding: 11px 22px; gap: 12px; border-radius: 7px; font-size: 13px; font-weight: 700; }
#request-form .create-request-ui .button.primary { color: #fff; background: #065df0; border-color: #065df0; }
#request-form .create-request-ui .request-details-actions .button.secondary { color: #065df0; border-color: #7da8fb; background: var(--surface-elevated); }
#request-form .create-request-ui .request-continue-icon { flex-shrink: 0; }
#request-form .create-request-ui .request-back-icon { transform: rotate(180deg); }

/* Item search and the selected-item table. */
#request-form .create-request-ui .request-picker-card { overflow: visible; position: relative; z-index: 1; }
#request-form .create-request-ui .request-picker-card .request-card-header { position: relative; padding-bottom: 18px; }
#request-form .create-request-ui .request-picker-card .request-card-header::after { content: ""; position: absolute; right: 24px; bottom: 0; left: 24px; border-bottom: 1px solid var(--border); }
#request-form .create-request-ui .request-picker-card .request-card-header .meta { margin-top: 4px; font-size: 13px; line-height: 1.5; color: var(--text-muted); }
#request-form .create-request-ui .request-picker-card .request-card-body { padding: 0 24px 15px; }
#request-form .create-request-ui .request-picker-card .request-premises-panel { display: block; min-width: 0; margin: 20px 0 16px; padding: 0 5px 18px; border: 0; border-bottom: 1px solid var(--border); border-radius: 0; background: transparent; }
#request-form .create-request-ui .request-premises-panel legend { float: none; width: auto; margin: 0 0 8px; padding: 0; color: var(--text-secondary); font-size: 13px; font-weight: 650; line-height: 1.5; }
#request-form .create-request-ui .request-premises-options { display: flex; flex-wrap: wrap; gap: 14px; }
#request-form .create-request-ui .request-premises-option { display: inline-flex; align-items: center; gap: 15px; width: 200px; max-width: 100%; min-height: 46px; margin: 0; padding: 10px 15px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface-elevated); color: var(--text-secondary); font-size: 13px; font-weight: 700; cursor: pointer; transition: background-color .15s ease, border-color .15s ease, color .15s ease; }
#request-form .create-request-ui .request-premises-option input[type="radio"] { flex-shrink: 0; width: 19px; height: 19px; min-height: 19px; margin: 0; accent-color: #065df0; }
#request-form .create-request-ui .request-premises-option:has(:checked) { color: var(--heading); background: #e6f0ff; border-color: #9fc6ff; }
#request-form .create-request-ui .request-premises-option:is(:hover, :focus-within) { color: #fff; background: #065df0; border-color: #065df0; }
#request-form .create-request-ui .request-premises-option:focus-within { box-shadow: 0 0 0 3px rgba(6, 93, 240, .15); }
#request-form .create-request-ui #request-premises-help { margin: 6px 0 0; color: var(--text-muted); font-size: 12px; font-weight: 400; line-height: 1.5; }
#request-form .create-request-ui .inventory-search-label { color: var(--heading); font-size: 13px; font-weight: 650; }
#request-form .create-request-ui .inventory-search-label .search-input-shell { margin-top: 8px; }
#request-form .create-request-ui #inventory-search { height: 43px; padding-left: 43px; border: 1px solid var(--border); border-radius: 7px; font-size: 13px; background-color: var(--surface-elevated); }
#request-form .create-request-ui .catalog-shell { background: var(--surface-elevated); }
#request-form .create-request-ui .catalog-summary { background: var(--surface-subtle); }
#request-form .create-request-ui .selected-items-header { margin: 16px 0 8px; }
#request-form .create-request-ui .selected-items-header h3 { font-size: 13px; font-weight: 750; color: var(--heading); }
#request-form .create-request-ui .selected-count { min-height: 30px; padding: 5px 14px; color: var(--text-secondary); background: var(--surface-subtle); border-color: var(--border); font-size: 12px; }
#request-form .create-request-ui .selected-items-table { overflow-x: auto; border: 1px solid var(--border); border-radius: 9px; box-shadow: 0 1px 3px rgba(16, 42, 67, .06); }
#request-form .create-request-ui #selected-items-table { width: 100%; min-width: 720px; margin: 0; border-collapse: collapse; table-layout: fixed; }
#request-form .create-request-ui #selected-items-table th { padding: 12px 18px; color: #fff; background: #08244b; font-size: 11px; font-weight: 750; letter-spacing: 0; border: 0; }
#request-form .create-request-ui #selected-items-table th:nth-child(1) { width: 40%; }
#request-form .create-request-ui #selected-items-table th:nth-child(2) { width: 25%; }
#request-form .create-request-ui #selected-items-table th:nth-child(3) { width: 21%; }
#request-form .create-request-ui #selected-items-table th:nth-child(4) { width: 14%; }
#request-form .create-request-ui #selected-items-table td { padding: 12px 18px; border-bottom: 1px solid var(--border); background: var(--surface-elevated); vertical-align: middle; }
#request-form .create-request-ui #selected-items-table tbody tr:last-child td { border-bottom: 0; }
#request-form .create-request-ui .selected-item-name > div { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; }
#request-form .create-request-ui .selected-item-name .item-code-badge { min-height: 20px; padding: 1px 7px; border-radius: 5px; line-height: 1.5; }
#request-form .create-request-ui .selected-item-name strong { color: var(--heading); font-size: 13px; font-weight: 700; line-height: 1.5; }
#request-form .create-request-ui .selected-item-name small { font-size: 11px; color: var(--text-muted); }
#request-form .create-request-ui .selected-availability { gap: 5px; }
#request-form .create-request-ui .selected-availability strong { color: var(--heading); font-size: 14px; }
#request-form .create-request-ui .selected-availability small { color: var(--text-muted); font-size: 11px; line-height: 1.5; }
#request-form .create-request-ui .selected-availability small.is-error { color: var(--danger); font-weight: 700; }
#request-form .create-request-ui .selected-quantity { width: 116px; min-width: 70px; max-width: 100%; height: 42px; min-height: 42px; margin: 0; padding: 9px 12px; border: 1px solid var(--border); border-radius: 7px; color: var(--heading); background: var(--surface-elevated); font-size: 14px; }
#request-form .create-request-ui .request-remove-button { min-height: 40px; padding: 9px 14px; border-color: var(--border); border-radius: 7px; background: var(--surface-elevated); color: var(--text-secondary); font-size: 11px; font-weight: 700; }
#request-form .create-request-ui .request-picker-actions { justify-content: space-between; gap: 14px; margin-top: -16px; padding: 15px 19px; border: 1px solid var(--border); border-radius: 12px; background: var(--surface-elevated); }
#request-form .create-request-ui .request-picker-actions .button.primary { min-width: 265px; justify-content: space-between; }
#request-form .create-request-ui .request-picker-actions .button.secondary { color: var(--text-secondary); border-color: var(--border); background: var(--surface-elevated); }

/* Enabled action buttons turn solid blue; tracker buttons are deliberately excluded. */
#request-form .create-request-ui .button:not(:disabled):not([aria-disabled="true"]):is(:hover, :focus-visible) { color: #fff; background: #065df0; border-color: #065df0; box-shadow: none; transform: none; }
#request-form .create-request-ui .button:not(:disabled):not([aria-disabled="true"]):focus-visible { outline: 2px solid #065df0; outline-offset: 3px; }
#request-form .create-request-ui .button:disabled { cursor: not-allowed; }
@media (max-width: 700px) {
    #request-form .create-request-ui .request-details-card .request-card-header,
    #request-form .create-request-ui .request-picker-card .request-card-header { padding: 20px 16px 16px; }
    #request-form .create-request-ui .request-picker-card .request-card-header::after { right: 16px; left: 16px; }
    #request-form .create-request-ui .request-details-card .request-information-fields,
    #request-form .create-request-ui .request-picker-card .request-card-body { padding-left: 16px; padding-right: 16px; }
    #request-form .create-request-ui .request-details-card .field-grid { gap: 18px; }
    #request-form .create-request-ui .request-schedule-fields,
    #request-form .create-request-ui .request-details-card .student-activity-panel { padding: 20px 16px; }
    #request-form .create-request-ui .request-details-actions,
    #request-form .create-request-ui .request-picker-actions { padding: 16px; }
    #request-form .create-request-ui .request-picker-actions .button.primary { min-width: 0; justify-content: center; }
    #request-form .create-request-ui .request-premises-options { gap: 10px; }
    #request-form .create-request-ui .request-premises-option { flex: 1; width: auto; min-width: 140px; padding: 10px; gap: 10px; }
}
[data-theme="dark"] #request-form .create-request-ui .request-premises-option:has(:checked) { color: var(--heading); background: #163967; border-color: #4c88eb; }
[data-theme="dark"] #request-form .create-request-ui .request-premises-option:is(:hover, :focus-within) { color: #fff; background: #065df0; border-color: #065df0; }
</style>

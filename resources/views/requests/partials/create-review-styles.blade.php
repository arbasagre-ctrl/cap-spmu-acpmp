<style>
/* Step 3 — Documents & Review */
#request-form .create-request-ui .request-card.review-card,
#request-form .create-request-ui .request-card.documents-card,
#request-form .create-request-ui .request-card.confirmation-card {
    display: grid;
    gap: 14px;
    padding: 22px 24px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
}

.request-section-label { margin: 0; color: #0f62d6; font-size: 12px; font-weight: 800; letter-spacing: .07em; text-transform: uppercase; }
.request-section-copy { margin: -8px 0 0; color: var(--text-muted); font-size: 12.5px; line-height: 1.55; }
.request-section-rule { width: 100%; height: 0; margin: 4px 0; border: 0; border-top: 1px solid var(--border); }

/* Review summary fields */
.review-summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 32px; }
.review-summary-field { display: grid; gap: 6px; min-width: 0; }
.review-summary-field > span { color: var(--text-muted); font-size: 12px; font-weight: 700; }
.review-summary-field > strong { color: var(--heading); font-size: 13.5px; font-weight: 650; line-height: 1.45; overflow-wrap: anywhere; }
.review-summary-pill { justify-self: start; display: inline-flex; align-items: center; padding: 4px 11px; border: 1px solid transparent; border-radius: 6px; font-size: 12px; font-weight: 650; }
.review-summary-pill.is-positive { color: var(--success); background: var(--success-bg); border-color: var(--success-border); }
.review-summary-pill.is-elevated { color: var(--warning); background: var(--warning-bg); border-color: var(--warning-border); }
.review-summary-pill.is-neutral { color: var(--text-secondary); background: var(--surface-subtle); border-color: var(--border); }

/* Selected items */
.review-items-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px 16px; }
#request-form .create-request-ui .review-items-header h3 { margin: 0; color: var(--heading); font-size: 15px; font-weight: 750; }
.review-items-count { display: inline-flex; align-items: center; padding: 5px 12px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-subtle); color: var(--text-secondary); font-size: 12px; font-weight: 650; white-space: nowrap; }
#request-form .create-request-ui .review-items-table { border: 1px solid var(--border); border-radius: 8px; }
#request-form .create-request-ui .review-items-table table { min-width: 560px; }
#request-form .create-request-ui .review-items-table th { padding: 12px 14px; border-bottom: 1px solid var(--border); background: var(--table-heading-bg); color: var(--text-secondary); font-size: 10px; font-weight: 750; letter-spacing: .05em; text-transform: uppercase; }
#request-form .create-request-ui .review-items-table td { padding: 13px 14px; border-bottom: 1px solid var(--row-border); color: var(--heading); font-size: 12.5px; vertical-align: middle; }
#request-form .create-request-ui .review-items-table tbody tr:last-child td { border-bottom: 0; }
.review-item-cell { display: flex; align-items: center; gap: 12px; }
.review-item-name { min-width: 0; font-weight: 650; overflow-wrap: anywhere; }
.review-items-empty { color: var(--text-muted); text-align: center; }

/* Required documents */
/* Both letters sit side by side; a hidden one lets the other span the row. */
.document-rows { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: stretch; gap: 16px; }
.document-row { display: grid; align-content: start; gap: 16px; padding: 18px 20px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.document-row-info { display: grid; gap: 6px; min-width: 0; }
.document-row-info > strong { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; color: var(--heading); font-size: 13.5px; font-weight: 700; }
.document-required { color: var(--danger); font-size: 11.5px; font-weight: 700; }
.document-row-info > p { margin: 0; color: var(--text-muted); font-size: 12.5px; line-height: 1.5; }
.document-current { margin: 2px 0 0 !important; font-size: 12px; }

#request-form .create-request-ui .document-dropzone { position: relative; display: grid; justify-items: center; gap: 4px; margin: 0; padding: 20px 16px; border: 1.5px dashed var(--border-strong); border-radius: 9px; background: var(--surface-subtle); text-align: center; cursor: pointer; transition: border-color var(--motion) ease, background-color var(--motion) ease; }
#request-form .create-request-ui .document-dropzone:hover,
#request-form .create-request-ui .document-dropzone.is-dragging { border-color: #0f62d6; background: var(--info-bg); }
#request-form .create-request-ui .document-dropzone.has-file { border-style: solid; border-color: var(--success-border); background: var(--success-bg); }
#request-form .create-request-ui .document-dropzone input[type="file"] { position: absolute; inset: 0; width: 100%; height: 100%; min-height: 0; padding: 0; margin: 0; border: 0; opacity: 0; cursor: pointer; }
.document-dropzone-icon { display: flex; align-items: center; gap: 9px; color: #0f62d6; }
.document-dropzone-icon strong { color: var(--heading); font-size: 13.5px; font-weight: 700; }
.document-dropzone-hint { color: var(--text-muted); font-size: 12.5px; }
.document-dropzone-formats { color: var(--text-soft); font-size: 11.5px; }
.document-dropzone-file { max-width: 100%; margin-top: 4px; color: var(--success); font-size: 12px; font-weight: 700; overflow-wrap: anywhere; }

/* Final confirmation */
#request-form .create-request-ui .confirmation-card .final-confirmation { display: grid; grid-template-columns: auto minmax(0, 1fr); align-items: start; gap: 12px; margin: 0; padding: 0; border: 0; background: transparent; }
#request-form .create-request-ui .confirmation-card .final-confirmation input[type="checkbox"] { width: 17px; height: 17px; min-height: 0; margin: 2px 0 0; flex-shrink: 0; accent-color: #0f62d6; }
#request-form .create-request-ui .confirmation-card .final-confirmation > span { display: grid; gap: 4px; min-width: 0; }
#request-form .create-request-ui .confirmation-card .final-confirmation strong { color: var(--heading); font-size: 13px; font-weight: 700; line-height: 1.45; }
#request-form .create-request-ui .confirmation-card .final-confirmation small { color: var(--text-muted); font-size: 12px; line-height: 1.5; }

.esignature-notice { display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px; border: 1px solid var(--warning-border); border-radius: 8px; background: var(--warning-bg); }
.esignature-notice > .ui-icon { flex-shrink: 0; margin-top: 1px; color: var(--warning); }
.esignature-notice strong { display: block; margin-bottom: 3px; color: var(--warning); font-size: 13px; font-weight: 700; }
.esignature-notice p { margin: 0; color: var(--text-secondary); font-size: 12.5px; line-height: 1.5; }
.esignature-notice a { color: #0f62d6; font-weight: 700; }

/* Review action bar */
#request-form .create-request-ui .sticky-actions.request-review-actions { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px 18px; padding: 16px 20px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); box-shadow: var(--shadow-sm); }
.request-review-actions .actions { margin-left: auto; gap: 10px; }
#request-form .create-request-ui .request-submit-button { gap: 9px; }

html[data-theme="dark"] .request-section-label,
html[data-theme="dark"] .document-dropzone-icon,
html[data-theme="dark"] .esignature-notice a { color: #72b7f4; }
html[data-theme="dark"] #request-form .create-request-ui .document-dropzone:hover,
html[data-theme="dark"] #request-form .create-request-ui .document-dropzone.is-dragging { border-color: #72b7f4; }

@media (max-width: 820px) {
    .review-summary-grid { grid-template-columns: minmax(0, 1fr); gap: 16px; }
    .document-rows { grid-template-columns: minmax(0, 1fr); }
    #request-form .create-request-ui .request-card.review-card,
    #request-form .create-request-ui .request-card.documents-card,
    #request-form .create-request-ui .request-card.confirmation-card { padding: 16px 14px; }
}
@media (max-width: 620px) {
    #request-form .create-request-ui .sticky-actions.request-review-actions { align-items: stretch; }
    .request-review-actions .actions { margin-left: 0; }
    .request-review-actions .button { flex: 1 1 auto; justify-content: center; }
}
@media (prefers-reduced-motion: reduce) {
    #request-form .create-request-ui .document-dropzone { transition: none; }
}
</style>

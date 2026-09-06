<style>
/* Create Request — guided validation states and spacing cleanup */
#request-form .create-request-ui {
    gap: 22px;
}

/* Do not visually overlap the Select Items card and its action bar. */
#request-form .create-request-ui .request-picker-actions {
    margin-top: 0 !important;
}

/* Give contextual messages breathing room without making the form feel bulky. */
#request-form .create-request-ui .picker-warning {
    margin-top: 16px;
}

#request-form .create-request-ui .picker-guidance {
    border-left-width: 3px;
}

/* Disabled actions must look disabled, not like active primary actions. */
#request-form .create-request-ui .button:disabled,
#request-form .create-request-ui .button[disabled] {
    opacity: 1;
    cursor: not-allowed;
    color: var(--text-muted) !important;
    background: var(--surface-subtle) !important;
    border-color: var(--border) !important;
    box-shadow: none !important;
    transform: none !important;
}

#request-form .create-request-ui .button:disabled .ui-icon,
#request-form .create-request-ui .button[disabled] .ui-icon {
    opacity: .65;
}

/* Step 3 reads as three clear sections rather than one crowded block. */
#request-form .create-request-ui .request-card.review-card,
#request-form .create-request-ui .request-card.documents-card,
#request-form .create-request-ui .request-card.confirmation-card {
    row-gap: 18px;
}

#request-form .create-request-ui .documents-card .document-rows {
    gap: 20px;
}

#request-form .create-request-ui .documents-card .document-row {
    gap: 18px;
    padding: 20px 22px;
}

#request-form .create-request-ui .confirmation-card .final-confirmation {
    gap: 14px;
}

#request-form .create-request-ui .sticky-actions.request-review-actions {
    margin-top: 2px;
}

@media (max-width: 820px) {
    #request-form .create-request-ui {
        gap: 16px;
    }

    #request-form .create-request-ui .documents-card .document-row {
        padding: 17px 16px;
    }
}
</style>


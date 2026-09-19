<script>
(() => {
    const workspace = document.querySelector('[data-release-process]');
    if (!workspace || workspace.dataset.releaseProcessInitialized === '1') return;
    workspace.dataset.releaseProcessInitialized = '1';

    const controls = [...workspace.querySelectorAll('[data-release-panel-toggle], [data-release-schedule-edit]')];
    const setPanel = (id, open, focus = false) => {
        const panel = document.getElementById(id);
        if (!panel || !workspace.contains(panel)) return;
        panel.hidden = !open;
        controls.forEach((control) => {
            if (control.getAttribute('aria-controls') === id) control.setAttribute('aria-expanded', String(open));
        });
        if (open && focus) panel.querySelector('input, button, textarea')?.focus();
    };

    controls.forEach((control) => {
        control.addEventListener('click', () => {
            const id = control.getAttribute('aria-controls');
            const edit = control.hasAttribute('data-release-schedule-edit');
            setPanel(id, edit || control.getAttribute('aria-expanded') !== 'true', edit);
        });
    });

    workspace.querySelector('[data-release-schedule-cancel]')?.addEventListener('click', () => {
        workspace.querySelector('#release-schedule-editor form')?.reset();
        setPanel('release-schedule-editor', false);
        workspace.querySelector('[data-release-schedule-edit]')?.focus();
    });


    const preparationForm = workspace.querySelector('[data-item-preparation-form]');
    if (preparationForm) {
        const issueType = preparationForm.querySelector('[data-preparation-issue-type]');
        const quantityField = preparationForm.querySelector('[data-preparation-quantity-field]');
        const quantityInput = quantityField?.querySelector('input');
        const conditionField = preparationForm.querySelector('[data-preparation-condition-field]');
        const conditionInput = conditionField?.querySelector('input');
        const detailsField = preparationForm.querySelector('[data-preparation-details-field]');
        const detailsInput = preparationForm.querySelector('[data-preparation-details-input]');
        const detailsLabel = preparationForm.querySelector('[data-preparation-details-label]');

        const setFieldState = (field, input, visible, required = false) => {
            if (!field || !input) return;
            field.hidden = !visible;
            input.disabled = !visible;
            input.required = visible && required;
        };

        const syncPreparationIssueFields = () => {
            const value = issueType?.value || '';
            const quantityShort = value === 'QUANTITY_AVAILABILITY';
            const conditionIssue = value === 'PHYSICAL_CONDITION';
            const hasOptionalRemarks = ['ITEM_NOT_READY', 'QUANTITY_AVAILABILITY', 'PHYSICAL_CONDITION'].includes(value);
            const otherIssue = value === 'OTHER';

            setFieldState(quantityField, quantityInput, quantityShort, quantityShort);
            setFieldState(conditionField, conditionInput, conditionIssue, conditionIssue);
            setFieldState(detailsField, detailsInput, hasOptionalRemarks || otherIssue, otherIssue);

            if (detailsLabel) detailsLabel.textContent = otherIssue ? 'Details' : 'Remarks (Optional)';
            if (detailsInput) {
                detailsInput.placeholder = otherIssue
                    ? 'Describe the inventory discrepancy.'
                    : 'Add a short note only if needed.';
            }
        };

        issueType?.addEventListener('change', syncPreparationIssueFields);
        syncPreparationIssueFields();
    }

    workspace.querySelector('[data-release-cancel-request-form]')?.addEventListener('submit', (event) => {
        const confirmed = window.confirm(
            'Cancel this unreleased borrowing request? Use this only after confirming that the borrower will no longer proceed. The reserved quantity will be restored to Available inventory and the record will remain in Request Records as Cancelled.'
        );

        if (!confirmed) event.preventDefault();
    });
})();
</script>

<script>
(() => {
    const flash = document.querySelector('[data-return-flash]');
    if (flash && flash.dataset.dismissInitialized !== '1') {
        flash.dataset.dismissInitialized = '1';
        flash.querySelector('[data-return-flash-dismiss]')?.addEventListener('click', () => {
            flash.hidden = true;
        });
    }

    /*
     * Linen return date:
     * source of truth = Laundry Form RECEIVED BY date.
     * Upload/encoding date does not determine borrower timeliness.
     */
    const laundryDateInput = document.querySelector('[data-laundry-return-date]');
    const laundryDateStatus = document.querySelector('[data-laundry-return-date-status]');

    const dateOrdinal = (value) => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
        if (!match) return null;

        return Math.floor(
            Date.UTC(
                Number(match[1]),
                Number(match[2]) - 1,
                Number(match[3])
            ) / 86400000
        );
    };

    const refreshLaundryDateStatus = () => {
        if (!laundryDateInput || !laundryDateStatus) return;

        const selected = laundryDateInput.value;
        const due = laundryDateInput.dataset.dueDate || '';

        if (!selected) {
            laundryDateStatus.textContent = '';
            return;
        }

        if (laundryDateInput.min && selected < laundryDateInput.min) {
            laundryDateStatus.textContent =
                'Date cannot be earlier than the physical release date.';
            return;
        }

        const selectedDay = dateOrdinal(selected);
        const dueDay = dateOrdinal(due);

        if (selectedDay === null || dueDay === null) {
            laundryDateStatus.textContent = '';
            return;
        }

        const difference = selectedDay - dueDay;

        laundryDateStatus.textContent = difference > 0
            ? `Late return — ${difference} day${difference === 1 ? '' : 's'} after the expected return date.`
            : 'On time based on the Laundry Form RECEIVED BY date.';
    };

    if (laundryDateInput && laundryDateInput.dataset.dynamicDateInitialized !== '1') {
        laundryDateInput.dataset.dynamicDateInitialized = '1';
        laundryDateInput.addEventListener('input', refreshLaundryDateStatus);
        laundryDateInput.addEventListener('change', refreshLaundryDateStatus);
        refreshLaundryDateStatus();
    }

    const form = document.getElementById('full-return-accounting-form');
    if (!form || form.dataset.returnInspectionInitialized === '1') return;
    form.dataset.returnInspectionInitialized = '1';

    const rows = [...form.querySelectorAll('.return-accounting-row')];
    const button = form.querySelector('#record-return-button');
    const message = form.querySelector('#return-accounting-message');
    const messageCopy = message?.querySelector('[data-return-accounting-copy]');
    const warningIcon = message?.querySelector('[data-return-accounting-warning]');
    const successIcon = message?.querySelector('[data-return-accounting-success]');
    const remarks = form.querySelector('textarea[name="remarks"]');
    const remarksCount = form.querySelector('[data-return-remarks-count]');
    const epsilon = 0.0005;

    const mixedReturn = form.dataset.mixedReturn === '1';
    const nonLinenOnly = form.dataset.nonLinenOnly === '1';
    const linenOnly = form.dataset.linenOnly === '1';
    const hasPendingLinen = rows.some((row) => row.dataset.linenPending === '1');

    const numberValue = (input) => {
        const value = Number.parseFloat(input?.value || '0');
        return Number.isFinite(value) && value > 0 ? value : 0;
    };

    const refreshRemarksCount = () => {
        if (remarks && remarksCount) {
            remarksCount.textContent = String(remarks.value.length);
        }
    };

    remarks?.addEventListener('input', refreshRemarksCount);
    refreshRemarksCount();

    const refresh = () => {
        let selectedRows = 0;
        let selectedNonLinenRows = 0;
        let selectedLinenRows = 0;
        let allSelectedRowsComplete = true;
        let browserValidityOkay = true;
        let availableRows = 0;

        rows.forEach((row) => {
            const linenPending = row.dataset.linenPending === '1';
            const kind = row.dataset.returnKind || 'non-linen';
            const outstanding = Number.parseFloat(row.dataset.outstanding || '0');
            const inputs = [...row.querySelectorAll('.return-accounting-input')];

            if (linenPending) return;

            availableRows += 1;

            if (inputs.some((input) => !input.validity.valid)) {
                browserValidityOkay = false;
            }

            const total = inputs.reduce((sum, input) => sum + numberValue(input), 0);
            const nonFine = inputs
                .filter((input) => input.dataset.condition !== 'FINE')
                .reduce((sum, input) => sum + numberValue(input), 0);
            const stolen = numberValue(row.querySelector('[data-condition="STOLEN"]'));

            const detailsRow = row.nextElementSibling?.matches('[data-return-issue-details]')
                ? row.nextElementSibling
                : null;

            const evidence = detailsRow?.querySelector('.return-evidence-input');
            const police = detailsRow?.querySelector('.return-police-input');
            const policeWrap = detailsRow?.querySelector('[data-police-wrap]');
            const totalLabel = row.querySelector('.return-accounted-total');
            const stateLabel = row.querySelector('.return-accounted-state');

            if (detailsRow) detailsRow.hidden = nonFine <= epsilon;
            if (policeWrap) policeWrap.hidden = stolen <= epsilon;
            if (evidence) evidence.required = nonFine > epsilon;
            if (police) police.required = stolen > epsilon;

            if (totalLabel) {
                totalLabel.textContent = `${total} / ${outstanding}`;
            }

            if (total <= epsilon) {
                if (stateLabel) {
                    stateLabel.textContent = 'Not returned in this inspection';
                }
                return;
            }

            selectedRows += 1;

            if (kind === 'linen') {
                selectedLinenRows += 1;
            } else {
                selectedNonLinenRows += 1;
            }

            const complete = Math.abs(total - outstanding) <= epsilon;

            if (!complete) {
                allSelectedRowsComplete = false;
            }

            if (stateLabel) {
                const percent = outstanding > 0
                    ? Math.round((total / outstanding) * 100)
                    : 0;
                stateLabel.textContent = `${percent}% accounted`;
            }
        });

        if (!button || availableRows === 0) {
            if (button) button.disabled = true;
            if (message) message.hidden = true;
            return;
        }

        if (message) {
            message.hidden = selectedRows === 0;
        }

        /*
         * Final agreed workflow:
         *
         * NON-LINEN
         * Actual return date/time = AO physical inspection/recording event.
         *
         * LINEN
         * Actual return date = Laundry Form RECEIVED BY date.
         *
         * In a mixed custody, either branch may be recorded independently.
         * Non-linen does not wait for the Laundry Form. Linen can be recorded
         * later using its original RECEIVED BY date. This keeps the UI simple
         * while preserving the correct return date source for each branch.
         */
        const bothBranchesSelected =
            selectedNonLinenRows > 0 && selectedLinenRows > 0;

        const ready =
            selectedRows > 0
            && allSelectedRowsComplete
            && browserValidityOkay
            && !bothBranchesSelected;

        button.disabled = !ready;

        message?.classList.toggle('warning', !ready);
        message?.classList.toggle('success', ready);

        if (warningIcon) warningIcon.hidden = ready;
        if (successIcon) successIcon.hidden = !ready;

        if (!messageCopy) return;

        if (bothBranchesSelected) {
            button.textContent = 'Record Return Inspection';
            messageCopy.textContent =
                'Record one return branch at a time.';
            return;
        }

        if (ready && selectedNonLinenRows > 0) {
            button.textContent =
                mixedReturn
                    ? 'Record Non-Linen Return Inspection'
                    : 'Record Return Inspection';

            messageCopy.textContent =
                hasPendingLinen
                    ? 'Ready to record non-linen. Linen remains pending.'
                    : 'Ready to record return inspection.';
            return;
        }

        if (ready && selectedLinenRows > 0) {
            button.textContent = 'Record Linen Return Findings';
            messageCopy.textContent =
                'Ready to record linen from the accomplished Laundry Form.';
            return;
        }

        if (selectedRows > 0 && !allSelectedRowsComplete) {
            messageCopy.textContent =
                'Account for the full outstanding quantity, or return that row to 0.';
            return;
        }

        if (nonLinenOnly) {
            button.textContent = 'Record Return Inspection';
            messageCopy.textContent =
                'Enter the non-linen items physically returned.';
            return;
        }

        if (linenOnly) {
            button.textContent = 'Record Linen Return Findings';
            messageCopy.textContent =
                'Enter the linen findings from the accomplished form.';
            return;
        }

        button.textContent =
            hasPendingLinen
                ? 'Record Non-Linen Return Inspection'
                : 'Record Return Inspection';

        messageCopy.textContent =
            hasPendingLinen
                ? 'Linen remains pending.'
                : 'Enter returned quantities.';
    };

    rows.forEach((row) => {
        row.querySelectorAll('.return-accounting-input').forEach((input) => {
            input.addEventListener('input', refresh);
            input.addEventListener('change', refresh);
        });
    });

    form.addEventListener('reset', () => {
        window.setTimeout(refresh, 0);
    });

    refresh();
})();
</script>

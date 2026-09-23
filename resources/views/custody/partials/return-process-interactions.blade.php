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

        laundryDateStatus.dataset.state = '';

        if (!selected) {
            laundryDateStatus.textContent = '';
            return;
        }

        if (laundryDateInput.min && selected < laundryDateInput.min) {
            laundryDateStatus.textContent = 'Date is before the physical release.';
            laundryDateStatus.dataset.state = 'error';
            return;
        }

        const selectedDay = dateOrdinal(selected);
        const dueDay = dateOrdinal(due);

        if (selectedDay === null || dueDay === null) {
            laundryDateStatus.textContent = '';
            return;
        }

        const difference = selectedDay - dueDay;
        if (difference > 0) {
            laundryDateStatus.textContent = `Late · ${difference} day${difference === 1 ? '' : 's'}`;
            laundryDateStatus.dataset.state = 'late';
        } else {
            laundryDateStatus.textContent = 'On time';
            laundryDateStatus.dataset.state = 'ontime';
        }
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
    const epsilon = 0.0005;

    const mixedReturn = form.dataset.mixedReturn === '1';
    const nonLinenOnly = form.dataset.nonLinenOnly === '1';
    const linenOnly = form.dataset.linenOnly === '1';
    const hasPendingLinen = rows.some((row) => row.dataset.linenPending === '1');

    const numberValue = (input) => {
        const value = Number.parseFloat(input?.value || '0');
        return Number.isFinite(value) && value > 0 ? value : 0;
    };

    const refresh = () => {
        const activeRows = rows.filter((row) => row.dataset.linenPending !== '1');
        const rowStates = [];

        activeRows.forEach((row) => {
            const kind = row.dataset.returnKind || 'non-linen';
            const outstanding = Number.parseFloat(row.dataset.outstanding || '0');
            const inputs = [...row.querySelectorAll('.return-accounting-input')];
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

            // Laundry Form = authoritative condition evidence for linen.
            // Extra linen photos/files are optional.
            if (evidence) evidence.required = kind !== 'linen' && nonFine > epsilon;
            if (police) police.required = stolen > epsilon;

            if (totalLabel) totalLabel.textContent = `${total} / ${outstanding}`;

            const selected = total > epsilon;
            const complete = selected && Math.abs(total - outstanding) <= epsilon;

            if (stateLabel) {
                if (!selected) {
                    stateLabel.textContent = 'Not yet accounted';
                } else if (complete) {
                    stateLabel.textContent = '100% accounted';
                } else {
                    const rawPercent = outstanding > 0
                        ? Math.round((total / outstanding) * 100)
                        : 0;
                    const percent = complete
                        ? 100
                        : (total > outstanding + epsilon ? rawPercent : Math.min(99, rawPercent));
                    stateLabel.textContent = `${percent}% accounted`;
                }
            }

            rowStates.push({ kind, selected, complete });
        });

        const branchState = (kind) => {
            const branchRows = rowStates.filter((state) => state.kind === kind);
            const selectedRows = branchRows.filter((state) => state.selected);
            const selected = selectedRows.length > 0;
            const complete = !selected || (
                selectedRows.length === branchRows.length
                && branchRows.every((state) => state.complete)
            );

            return {
                rows: branchRows.length,
                selectedRows: selectedRows.length,
                selected,
                complete,
            };
        };

        const nonLinen = branchState('non-linen');
        const linen = branchState('linen');
        const selectedBranches = Number(nonLinen.selected) + Number(linen.selected);
        const selectedRows = nonLinen.selectedRows + linen.selectedRows;
        const selectedBranchesComplete = nonLinen.complete && linen.complete;
        const browserValidityOkay = typeof form.checkValidity === 'function'
            ? form.checkValidity()
            : true;
        const ready = selectedBranches === 1 && selectedBranchesComplete && browserValidityOkay;

        if (!button || activeRows.length === 0) {
            if (button) button.disabled = true;
            return;
        }

        button.disabled = !ready;

        if (selectedBranches > 1) {
            button.textContent = 'Record Return Inspection';
            return;
        }

        if (nonLinen.selected) {
            button.textContent = mixedReturn
                ? 'Record Non-Linen Return Inspection'
                : 'Record Return Inspection';
            return;
        }

        if (linen.selected) {
            button.textContent = 'Record Linen Return Findings';
            return;
        }

        if (nonLinenOnly) {
            button.textContent = 'Record Return Inspection';
            return;
        }

        if (linenOnly) {
            button.textContent = 'Record Linen Return Findings';
            return;
        }

        button.textContent = hasPendingLinen
            ? 'Record Non-Linen Return Inspection'
            : 'Record Return Inspection';
    };

    rows.forEach((row) => {
        row.querySelectorAll('.return-accounting-input').forEach((input) => {
            input.addEventListener('input', refresh);
            input.addEventListener('change', refresh);
        });

        row.nextElementSibling
            ?.querySelectorAll('.return-evidence-input, .return-police-input')
            .forEach((input) => {
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

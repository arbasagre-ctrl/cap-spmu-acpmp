<script>
(() => {
    const flash = document.querySelector('[data-return-flash]');
    if (flash && flash.dataset.dismissInitialized !== '1') {
        flash.dataset.dismissInitialized = '1';
        flash.querySelector('[data-return-flash-dismiss]')?.addEventListener('click', () => {
            flash.hidden = true;
        });
    }

    // Classify the selected Laundry RECEIVED BY date against the expected return date.
    // The calendar has no upper cap so early, on-time, and late dates remain selectable.
    const laundryDateInput = document.querySelector('[data-laundry-return-date]');
    const laundryDateStatus = document.querySelector('[data-laundry-return-date-status]');

    if (laundryDateInput && laundryDateInput.dataset.dynamicDateInitialized !== '1') {
        laundryDateInput.dataset.dynamicDateInitialized = '1';

        const dateOrdinal = (value) => {
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
            if (!match) return null;
            return Math.floor(Date.UTC(
                Number(match[1]),
                Number(match[2]) - 1,
                Number(match[3])
            ) / 86400000);
        };

        const refreshLaundryDateStatus = () => {
            if (!laundryDateStatus) return;

            const selected = laundryDateInput.value;
            const due = laundryDateInput.dataset.dueDate || '';

            if (!selected) {
                laundryDateStatus.textContent = '';
                return;
            }

            if (laundryDateInput.min && selected < laundryDateInput.min) {
                laundryDateStatus.textContent = 'Date cannot be earlier than the release date.';
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
                ? `Late by ${difference} day${difference === 1 ? '' : 's'}.`
                : '';
        };

        laundryDateInput.addEventListener('change', refreshLaundryDateStatus);
        refreshLaundryDateStatus();
    }

    const form = document.getElementById('full-return-accounting-form');
    if (!form || form.dataset.returnInspectionInitialized === '1') return;
    form.dataset.returnInspectionInitialized = '1';

    const rows = [...form.querySelectorAll('.return-accounting-row')];
    const button = document.getElementById('record-return-button');
    const message = document.getElementById('return-accounting-message');
    const messageCopy = message.querySelector('[data-return-accounting-copy]');
    const warningIcon = message.querySelector('[data-return-accounting-warning]');
    const successIcon = message.querySelector('[data-return-accounting-success]');
    const remarks = form.querySelector('textarea[name="remarks"]');
    const remarksCount = form.querySelector('[data-return-remarks-count]');
    const epsilon = 0.0005;

    const refreshRemarksCount = () => {
        if (remarks && remarksCount) remarksCount.textContent = String(remarks.value.length);
    };
    remarks?.addEventListener('input', refreshRemarksCount);
    refreshRemarksCount();

    const numberValue = (input) => {
        const value = Number.parseFloat(input?.value || '0');
        return Number.isFinite(value) && value > 0 ? value : 0;
    };

    const refresh = () => {
        let selected = 0;
        let allValid = true;
        let availableRows = 0;

        rows.forEach((row) => {
            const linenPending = row.dataset.linenPending === '1';
            const outstanding = Number.parseFloat(row.dataset.outstanding || '0');
            const inputs = [...row.querySelectorAll('.return-accounting-input')];

            if (linenPending) {
                const totalLabel = row.querySelector('.return-accounted-total');
                const stateLabel = row.querySelector('.return-accounted-state');
                if (totalLabel) totalLabel.textContent = `0 / ${outstanding}`;
                if (stateLabel) stateLabel.textContent = 'Pending Form';
                return;
            }

            availableRows++;
            if (inputs.some((input) => !input.validity.valid)) allValid = false;
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

            if (totalLabel) totalLabel.textContent = `${total} / ${outstanding}`;

            if (total <= epsilon) {
                if (stateLabel) stateLabel.textContent = '0% accounted';
                return;
            }

            selected++;
            const complete = Math.abs(total - outstanding) <= epsilon;
            if (!complete) allValid = false;
            if (stateLabel) {
                const percent = outstanding > 0 ? Math.floor((total / outstanding) * 100) : 0;
                stateLabel.textContent = `${percent}% accounted`;
            }
        });

        // Linen rows with a pending form are disabled individually. They must
        // not block an otherwise valid non-linen inspection in the same custody.
        if (availableRows === 0) {
            button.disabled = true;
            message.hidden = true;
            return;
        }

        message.hidden = false;
        const ready = selected > 0 && allValid;
        button.disabled = !ready;
        message.classList.toggle('warning', !ready);
        message.classList.toggle('success', ready);
        if (warningIcon) warningIcon.hidden = ready;
        if (successIcon) successIcon.hidden = !ready;
        messageCopy.textContent = ready
            ? 'Ready to record inspection.'
            : (availableRows > 0
                ? 'Accounted quantities must match the outstanding total.'
                : 'Upload the Laundry Form to continue.');
    };

    rows.forEach((row) => {
        row.querySelectorAll('.return-accounting-input').forEach((input) => {
            input.addEventListener('input', refresh);
        });
    });

    refresh();
})();
</script>

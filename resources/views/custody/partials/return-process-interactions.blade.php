<script>
(() => {
    const flash = document.querySelector('[data-return-flash]');
    if (flash && flash.dataset.dismissInitialized !== '1') {
        flash.dataset.dismissInitialized = '1';
        flash.querySelector('[data-return-flash-dismiss]')?.addEventListener('click', () => {
            flash.hidden = true;
        });
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

        rows.forEach((row) => {
            const outstanding = Number.parseFloat(row.dataset.outstanding || '0');
            const inputs = [...row.querySelectorAll('.return-accounting-input')];
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

        // Mirrors the server-side linen rule in CustodyService::receiveReturn.
        const laundryFormMissing = form.dataset.laundryFormMissing === '1';
        const ready = selected > 0 && allValid && !laundryFormMissing;
        button.disabled = !ready;
        message.classList.toggle('warning', !ready);
        message.classList.toggle('success', ready);
        if (warningIcon) warningIcon.hidden = ready;
        if (successIcon) successIcon.hidden = !ready;
        messageCopy.textContent = laundryFormMissing
            ? 'Completed Laundry Form required before the linen return can be finalized.'
            : (ready
                ? 'Selected item quantities are fully accounted. You may record the SPMU inspection.'
                : 'For each selected item, Fine + Damaged + Destroyed + Missing + Lost + Stolen must equal its full outstanding quantity.');
    };

    rows.forEach((row) => {
        row.querySelectorAll('.return-accounting-input').forEach((input) => {
            input.addEventListener('input', refresh);
        });
    });

    refresh();
})();
</script>

<script>
(() => {
    const dialog = document.getElementById('report-options');
    if (!dialog) return;

    const form = dialog.querySelector('[data-export-form]');
    const format = dialog.querySelector('[data-export-format]');
    const marginPreset = dialog.querySelector('[data-export-margins]');
    const customMargins = dialog.querySelector('[data-custom-margins]');
    const submit = dialog.querySelector('[data-export-submit]');
    const error = dialog.querySelector('[data-export-error]');
    const pageSettings = Array.from(dialog.querySelectorAll('[data-page-settings]'));
    const summarySetting = dialog.querySelector('[data-summary-setting]');
    const paginatedNote = dialog.querySelector('[data-paginated-inclusions]');
    const spreadsheetNote = dialog.querySelector('[data-spreadsheet-inclusions]');
    const rawNote = dialog.querySelector('[data-raw-export-note]');

    const ACTION_LABELS = @json(App\Reports\ReportExportOptions::ACTION_LABELS);
    const printUrl = @json(route('reports.print', ['type' => $selectedReport]));

    const applyFormat = () => {
        const value = format.value;
        const paginated = ['pdf', 'docx', 'print'].includes(value);
        const spreadsheet = value === 'xlsx';
        const raw = value === 'csv';

        pageSettings.forEach((setting) => setting.hidden = !paginated);
        if (summarySetting) summarySetting.hidden = raw;
        if (paginatedNote) paginatedNote.hidden = !paginated;
        if (spreadsheetNote) spreadsheetNote.hidden = !spreadsheet;
        if (rawNote) rawNote.hidden = !raw;

        if (!paginated && customMargins) customMargins.hidden = true;
        submit.textContent = ACTION_LABELS[value] || 'Export';
    };

    const applyMargins = () => {
        if (!customMargins || !marginPreset) return;
        const currentFormat = format.value;
        const paginated = ['pdf', 'docx', 'print'].includes(currentFormat);
        customMargins.hidden = !paginated || marginPreset.value !== 'custom';
    };

    format.addEventListener('change', () => {
        applyFormat();
        applyMargins();
    });
    marginPreset?.addEventListener('change', applyMargins);

    form.addEventListener('submit', (event) => {
        if (format.value === 'print') {
            event.preventDefault();
            const url = new URL(printUrl, window.location.origin);
            const values = new FormData(form);

            values.forEach((value, key) => url.searchParams.append(key, value));
            url.searchParams.set('format', 'print');

            window.open(url.toString(), '_blank', 'noopener');
            dialog.close();
            return;
        }

        if (submit.disabled) {
            event.preventDefault();
            return;
        }

        submit.disabled = true;
        submit.dataset.idleLabel = submit.textContent;
        submit.textContent = 'Preparing…';
        error.hidden = true;

        window.setTimeout(() => {
            submit.disabled = false;
            submit.textContent = submit.dataset.idleLabel || 'Export';
        }, 4000);
    });

    document.querySelectorAll('[data-open-export-options]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            applyFormat();
            applyMargins();
            dialog.showModal();
        });
    });

    dialog.querySelectorAll('[data-export-close]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });

    applyFormat();
    applyMargins();
})();
</script>

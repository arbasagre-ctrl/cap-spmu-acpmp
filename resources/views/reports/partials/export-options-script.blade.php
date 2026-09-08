<script>
(() => {
    const dialog = document.getElementById('report-options');

    if (!dialog) {
        return;
    }

    const form = dialog.querySelector('[data-export-form]');
    const format = dialog.querySelector('[data-export-format]');
    const marginPreset = dialog.querySelector('[data-export-margins]');
    const customMargins = dialog.querySelector('[data-custom-margins]');
    const submit = dialog.querySelector('[data-export-submit]');
    const error = dialog.querySelector('[data-export-error]');
    const pageSettings = Array.from(dialog.querySelectorAll('[data-page-setting]'));

    const ACTION_LABELS = @json(App\Reports\ReportExportOptions::ACTION_LABELS);

    const printUrl = @json(route('reports.print', array_merge(
        ['type' => $selectedReport],
        $reportFilters->toQuery()
    )));

    /*
     * Page setup only means something for the paginated formats. CSV and
     * XLSX have no pages, so their controls are hidden rather than left on
     * screen doing nothing.
     */
    const applyFormat = () => {
        const value = format.value;
        const paginated = value === 'pdf' || value === 'docx' || value === 'print';

        pageSettings.forEach((setting) => {
            setting.hidden = !paginated;
        });

        submit.textContent = ACTION_LABELS[value] || 'Export';
    };

    const applyMargins = () => {
        customMargins.hidden = marginPreset.value !== 'custom';
    };

    format.addEventListener('change', applyFormat);
    marginPreset.addEventListener('change', applyMargins);

    form.addEventListener('submit', (event) => {
        /* Print opens the preview rather than downloading a file. */
        if (format.value === 'print') {
            event.preventDefault();
            window.open(printUrl, '_blank', 'noopener');
            dialog.close();

            return;
        }

        if (submit.disabled) {
            event.preventDefault();

            return;
        }

        submit.disabled = true;
        submit.dataset.idleLabel = submit.textContent;
        submit.textContent = 'Generating…';
        error.hidden = true;

        /*
         * The response is a file download, so the page never navigates and
         * there is no load event to reset on. Re-enable after a moment so a
         * second export is possible, and surface a failure if the browser
         * reports one.
         */
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
        if (event.target === dialog) {
            dialog.close();
        }
    });

    applyFormat();
    applyMargins();
})();
</script>

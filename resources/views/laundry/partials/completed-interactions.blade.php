<script>
(() => {
    const browser = document.querySelector('[data-completed-laundry]');
    if (!browser || browser.dataset.completedLaundryInitialized === '1') return;
    const search = browser.querySelector('[data-completed-search]');
    const outcome = browser.querySelector('[data-completed-outcome]');
    if (!search || !outcome) return;
    browser.dataset.completedLaundryInitialized = '1';

    const records = [...browser.querySelectorAll('[data-completed-record]')];
    const empty = browser.querySelector('[data-completed-empty]');
    const count = browser.querySelector('[data-completed-count]');
    const render = () => {
        const terms = search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
        const selectedOutcome = outcome.value;
        let visible = 0;

        records.forEach((record) => {
            const haystack = (record.dataset.search || '').toLocaleLowerCase();
            const outcomes = (record.dataset.outcomes || '').split(' ');
            const matches = terms.every((term) => haystack.includes(term))
                && (!selectedOutcome || outcomes.includes(selectedOutcome));
            record.hidden = !matches;
            if (matches) visible += 1;
        });

        if (empty) empty.hidden = visible !== 0;
        if (count) {
            const total = count.dataset.total || records.length;
            if (terms.length === 0 && !selectedOutcome) {
                count.textContent = `Showing ${count.dataset.first || 0} to ${count.dataset.last || 0} of ${total} completed cases`;
            } else {
                count.textContent = count.dataset.paginated === 'true'
                    ? `Showing ${visible} of ${records.length} cases on this page (${total} total)`
                    : `Showing ${visible} of ${total} completed cases`;
            }
        }
    };

    search.addEventListener('input', render);
    outcome.addEventListener('change', render);
    browser.querySelector('[data-completed-reset]')?.addEventListener('click', () => {
        search.value = '';
        outcome.value = '';
        render();
        search.focus();
    });
    render();
})();
</script>

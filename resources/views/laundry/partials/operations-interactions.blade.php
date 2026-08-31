<script>
(() => {
    const browser = document.querySelector('[data-laundry-browser]');
    if (!browser || browser.dataset.laundryBrowserInitialized === '1') return;

    const list = browser.querySelector('[data-laundry-list]');
    const search = browser.querySelector('[data-laundry-search]');
    if (!list || !search) return;
    browser.dataset.laundryBrowserInitialized = '1';

    const records = [...browser.querySelectorAll('[data-laundry-record]')];
    const status = browser.querySelector('[data-laundry-status]');
    const sort = browser.querySelector('[data-laundry-sort]');
    const count = browser.querySelector('[data-laundry-count]');
    const empty = browser.querySelector('[data-laundry-filter-empty]');

    const render = () => {
        const terms = search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
        const selectedStatus = status?.value || '';
        const oldestFirst = sort?.value === 'oldest';
        const ordered = [...records].sort((left, right) => {
            const difference = Number(left.dataset.date || 0) - Number(right.dataset.date || 0);
            return oldestFirst ? difference : -difference;
        });
        let visible = 0;

        ordered.forEach((record) => {
            const haystack = (record.dataset.search || '').toLocaleLowerCase();
            const matches = terms.every((term) => haystack.includes(term))
                && (!selectedStatus || record.dataset.status === selectedStatus);
            record.hidden = !matches;
            list.appendChild(record);
            if (matches) visible += 1;
        });

        if (empty) empty.hidden = visible !== 0;
        if (count) {
            const total = count.dataset.total || records.length;
            count.textContent = count.dataset.paginated === 'true'
                ? `Showing ${visible} of ${records.length} cases on this page (${total} total)`
                : `Showing ${visible} of ${total} cases`;
        }
    };

    search.addEventListener('input', render);
    status?.addEventListener('change', render);
    sort?.addEventListener('change', render);
    browser.querySelector('[data-laundry-reset]')?.addEventListener('click', () => {
        search.value = '';
        if (status) status.value = '';
        if (sort) sort.value = 'newest';
        render();
        search.focus();
    });

    render();
})();
</script>

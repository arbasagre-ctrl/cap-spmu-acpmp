<script>
(() => {
    const root = document.querySelector('[data-gate-pass-browser]');
    if (!root || root.dataset.gatePassInitialized === '1') return;
    const list = root.querySelector('[data-gate-pass-list]');
    const search = root.querySelector('[data-gate-pass-search]');
    if (!list || !search) return;
    root.dataset.gatePassInitialized = '1';

    const records = [...root.querySelectorAll('[data-gate-pass-record]')];
    const status = root.querySelector('[data-gate-pass-status]');
    const sort = root.querySelector('[data-gate-pass-sort]');
    const empty = root.querySelector('[data-gate-pass-empty]');
    const count = root.querySelector('[data-gate-pass-count]');
    const pagination = root.querySelector('[data-gate-pass-pagination]');
    const numbers = root.querySelector('[data-gate-pass-page-numbers]');
    const previous = root.querySelector('[data-gate-pass-page="previous"]');
    const next = root.querySelector('[data-gate-pass-page="next"]');
    const pageSize = Math.max(1, Number.parseInt(root.dataset.pageSize, 10) || 10);
    let page = 1;
    let pageCount = 1;

    const render = () => {
        const terms = search.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
        const selectedStatus = status.value;
        const oldestFirst = sort.value === 'oldest';
        const matches = [...records].sort((left, right) => {
            const difference = Number(left.dataset.date || 0) - Number(right.dataset.date || 0);
            return oldestFirst ? difference : -difference;
        }).filter(record => {
            const haystack = (record.dataset.search || '').toLocaleLowerCase();
            return terms.every(term => haystack.includes(term))
                && (!selectedStatus || record.dataset.status === selectedStatus);
        });
        pageCount = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.min(page, pageCount);
        const start = (page - 1) * pageSize;
        const visible = matches.slice(start, start + pageSize);
        records.forEach(record => {
            record.hidden = true;
            record.querySelectorAll('details[open]').forEach(menu => { menu.open = false; });
        });
        matches.forEach(record => list.appendChild(record));
        visible.forEach(record => { record.hidden = false; });
        empty.hidden = matches.length !== 0;
        count.textContent = matches.length
            ? `Showing ${start + 1} to ${start + visible.length} of ${matches.length} records`
            : 'Showing 0 records';
        pagination.hidden = matches.length === 0;
        previous.disabled = page === 1;
        next.disabled = page === pageCount;
        numbers.replaceChildren();

        const pages = [...new Set([1, pageCount, page - 1, page, page + 1])]
            .filter(number => number >= 1 && number <= pageCount).sort((a, b) => a - b);
        let last = 0;
        pages.forEach(number => {
            if (last && number - last > 1) {
                const ellipsis = document.createElement('span');
                ellipsis.className = 'gate-pass-page-ellipsis';
                ellipsis.textContent = '…';
                ellipsis.setAttribute('aria-hidden', 'true');
                numbers.appendChild(ellipsis);
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `icon-button gate-pass-page${number === page ? ' is-current' : ''}`;
            button.dataset.gatePassPage = String(number);
            button.textContent = String(number);
            button.setAttribute('aria-label', `Page ${number}`);
            if (number === page) button.setAttribute('aria-current', 'page');
            numbers.appendChild(button);
            last = number;
        });
    };

    const filter = () => { page = 1; render(); };
    search.addEventListener('input', filter);
    status.addEventListener('change', filter);
    sort.addEventListener('change', filter);
    pagination.addEventListener('click', event => {
        const target = event.target.closest('[data-gate-pass-page]');
        if (!target || target.disabled) return;
        const value = target.dataset.gatePassPage;
        const requested = value === 'previous' ? page - 1 : value === 'next' ? page + 1 : Number(value);
        if (!Number.isInteger(requested) || requested < 1 || requested > pageCount || requested === page) return;
        page = requested;
        render();
        numbers.querySelector('[aria-current="page"]')?.focus();
    });
    root.querySelector('[data-gate-pass-reset]')?.addEventListener('click', () => {
        search.value = '';
        status.value = '';
        sort.value = 'newest';
        filter();
        search.focus();
    });
    render();
})();
</script>

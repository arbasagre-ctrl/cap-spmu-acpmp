<script>
(() => {
    const initializeMyBorrowings = () => {
        const browser = document.querySelector('[data-my-borrowings]');

        if (!browser) {
            return;
        }

        const list = browser.querySelector('#borrowings-list');
        const rows = Array.from(browser.querySelectorAll('[data-borrowings-record]'));
        const search = browser.querySelector('#borrowings-search');
        const status = browser.querySelector('#borrowings-status');
        const sort = browser.querySelector('#borrowings-sort');
        const summary = browser.querySelector('#borrowings-result-summary');
        const empty = browser.querySelector('#borrowings-filter-empty');

        if (!list || rows.length === 0 || !search || !status || !sort) {
            return;
        }

        const render = () => {
            const term = search.value.trim().toLowerCase();
            const selectedStatus = status.value;
            const direction = sort.value === 'oldest' ? 1 : -1;

            const orderedRows = [...rows].sort((a, b) => {
                return direction * ((Number(a.dataset.borrowingsCreated) || 0) - (Number(b.dataset.borrowingsCreated) || 0));
            });

            orderedRows.forEach((row) => list.appendChild(row));

            let visibleCount = 0;

            orderedRows.forEach((row) => {
                const matchesSearch = term === '' || (row.dataset.borrowingsSearch || '').includes(term);
                const matchesStatus = selectedStatus === 'all' || (row.dataset.borrowingsStatus || '') === selectedStatus;
                const visible = matchesSearch && matchesStatus;

                row.hidden = !visible;
                if (visible) visibleCount += 1;
            });

            if (summary) {
                summary.textContent = `${visibleCount} ${visibleCount === 1 ? 'borrowing' : 'borrowings'}`;
            }

            if (empty) {
                empty.hidden = visibleCount !== 0;
            }
        };

        search.addEventListener('input', render);
        status.addEventListener('change', render);
        sort.addEventListener('change', render);
        render();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeMyBorrowings, { once: true });
    } else {
        initializeMyBorrowings();
    }
})();
</script>

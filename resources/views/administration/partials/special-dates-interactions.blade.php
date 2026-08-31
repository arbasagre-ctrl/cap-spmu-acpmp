<script>
(() => {
    const root = document.querySelector('[data-special-dates]');
    if (!root || root.dataset.specialDatesInitialized === '1') return;
    root.dataset.specialDatesInitialized = '1';

    const body = root.querySelector('[data-special-dates-body]');
    const search = root.querySelector('[data-special-dates-search]');
    const count = root.querySelector('[data-special-dates-count]');
    const noResults = root.querySelector('[data-special-dates-no-results]');
    const headers = [...root.querySelectorAll('[data-special-dates-sort]')];
    const rows = [...root.querySelectorAll('[data-special-date-row]')];
    if (!body) return;

    let sortKey = null;
    let ascending = true;

    const cellValue = (row, key) => {
        const cell = row.querySelector(`[data-sort="${key}"]`);
        if (!cell) return '';
        return cell.dataset.sortValue ?? cell.textContent.trim().toLocaleLowerCase();
    };

    const compare = (left, right) => {
        const a = cellValue(left, sortKey);
        const b = cellValue(right, sortKey);
        const numeric = a !== '' && b !== '' && !Number.isNaN(Number(a)) && !Number.isNaN(Number(b));
        const result = numeric ? Number(a) - Number(b) : String(a).localeCompare(String(b));
        return ascending ? result : -result;
    };

    const render = () => {
        const terms = (search?.value || '').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
        const matches = rows.filter(row => terms.every(
            term => (row.dataset.search || '').toLocaleLowerCase().includes(term)
        ));

        if (sortKey) {
            [...matches].sort(compare).forEach(row => body.appendChild(row));
        }

        rows.forEach(row => { row.hidden = !matches.includes(row); });
        if (count) count.textContent = String(matches.length);
        if (noResults) noResults.hidden = matches.length !== 0 || rows.length === 0;
    };

    headers.forEach(header => {
        header.addEventListener('click', () => {
            const key = header.dataset.specialDatesSort;
            ascending = key === sortKey ? !ascending : true;
            sortKey = key;

            headers.forEach(other => {
                const isActive = other === header;
                other.classList.toggle('is-sorted', isActive);
                other.closest('th')?.setAttribute(
                    'aria-sort',
                    isActive ? (ascending ? 'ascending' : 'descending') : 'none'
                );
            });

            render();
        });
    });

    search?.addEventListener('input', render);
    render();
})();
</script>

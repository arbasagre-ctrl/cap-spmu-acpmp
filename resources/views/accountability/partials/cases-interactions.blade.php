<script>
(() => {
    const search = document.getElementById('accountability-case-search');
    const toggle = document.getElementById('accountability-filter-toggle');
    const menu = document.getElementById('accountability-filter-menu');
    const body = document.getElementById('accountability-cases-body');
    const empty = document.getElementById('accountability-cases-none');
    if (!search || !toggle || !menu || !body || !empty) return;

    const rows = [...body.querySelectorAll('[data-case]')];
    const filters = [...menu.querySelectorAll('input[type="checkbox"]')];
    function filterCases() {
        const query = search.value.trim().toLocaleLowerCase();
        const statuses = new Set(filters.filter(input => input.checked).map(input => input.value));
        let visible = 0;
        rows.forEach(row => {
            const matches = row.dataset.search.toLocaleLowerCase().includes(query) && statuses.has(row.dataset.status);
            row.hidden = !matches;
            const detail = row.nextElementSibling;
            if (detail?.classList.contains('accountability-case-detail-row')) detail.hidden = !matches;
            if (matches) visible++;
        });
        empty.hidden = visible > 0;
    }
    function closeMenu() {
        menu.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }
    /*
     * A summary card can hand the queue a focus hint, so a reader who clicked
     * "Overdue Awaiting Return" lands on the rows that figure was counted
     * from. It only unticks statuses in the filter already on the page: it
     * narrows nothing the server did not return, and an unknown hint is
     * ignored rather than emptying the queue.
     */
    const FOCUS = {
        overdue: ['OVERDUE'],
        late: ['RETURNED_PENDING_SETTLEMENT', 'FOR_HEAD_APPROVAL', 'BILLED'],
    };

    const wanted = FOCUS[new URLSearchParams(location.search).get('focus')];

    if (wanted && filters.some(input => wanted.includes(input.value))) {
        filters.forEach(input => { input.checked = wanted.includes(input.value); });
        filterCases();
    }

    search.addEventListener('input', filterCases);
    filters.forEach(input => input.addEventListener('change', filterCases));
    toggle.addEventListener('click', () => {
        menu.hidden = !menu.hidden;
        toggle.setAttribute('aria-expanded', String(!menu.hidden));
    });
    document.addEventListener('click', event => {
        if (!menu.contains(event.target) && !toggle.contains(event.target)) closeMenu();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !menu.hidden) {
            closeMenu();
            toggle.focus();
        }
    });
})();
</script>

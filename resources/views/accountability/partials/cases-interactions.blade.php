<script>
/* One row, one disclosure. Filtering never changes workflow state. */
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-case-toggle]');
    if (!button) return;

    const row = button.closest('tr');
    const detail = row?.nextElementSibling;
    if (!detail?.classList.contains('accountability-case-detail-row')) return;

    const wasExpanded = detail.dataset.expanded === 'true';
    detail.dataset.expanded = String(!wasExpanded);
    detail.hidden = wasExpanded;
    button.setAttribute('aria-expanded', String(!wasExpanded));
});

(() => {
    const body = document.getElementById('accountability-cases-body');
    const empty = document.getElementById('accountability-cases-none');
    if (!body || !empty) return;

    const rows = [...body.querySelectorAll('[data-case]')];
    if (rows.length === 0) return;

    const search = document.getElementById('accountability-case-search');
    const typeSelect = document.getElementById('accountability-case-type');
    const statusSelect = document.getElementById('accountability-case-status');
    const count = document.getElementById('accountability-case-count');

    const rowMatchesType = (row, type) => {
        if (!type || type === 'all') return true;
        if (type === 'RESTRICTION') return row.dataset.hasRestriction === '1';
        return row.dataset.caseType === type;
    };

    /* A status that does not exist for the selected type is hidden/disabled.
       This prevents combinations that look broken but can never return rows. */
    function syncStatusOptions() {
        if (!statusSelect) return;

        const type = typeSelect?.value || 'all';
        const eligibleRows = rows.filter(row => rowMatchesType(row, type));

        [...statusSelect.options].forEach(option => {
            if (option.value === 'all') {
                option.hidden = false;
                option.disabled = false;
                return;
            }

            const available = eligibleRows.some(row => row.dataset.status === option.value);
            option.hidden = !available;
            option.disabled = !available;
        });

        const selected = statusSelect.selectedOptions[0];
        if (selected?.disabled || selected?.hidden) {
            statusSelect.value = 'all';
        }
    }

    function filterCases() {
        const query = search?.value.trim().toLocaleLowerCase() || '';
        const type = typeSelect?.value || 'all';
        const status = statusSelect?.value || 'all';
        let visible = 0;

        rows.forEach(row => {
            const matchesSearch = !query || (row.dataset.search || '').toLocaleLowerCase().includes(query);
            const matchesType = rowMatchesType(row, type);
            const matchesStatus = status === 'all' || row.dataset.status === status;
            const matches = matchesSearch && matchesType && matchesStatus;

            row.hidden = !matches;

            const detail = row.nextElementSibling;
            if (detail?.classList.contains('accountability-case-detail-row')) {
                detail.hidden = !matches || detail.dataset.expanded !== 'true';
            }

            if (matches) visible++;
        });

        empty.hidden = visible > 0;
        if (count) count.textContent = String(visible);
    }

    /* Summary-card focus links can still open the correct subset, but only
       when that control is actually useful enough to be rendered. */
    const focus = new URLSearchParams(location.search).get('focus');

    if (focus === 'overdue') {
        if (typeSelect && [...typeSelect.options].some(option => option.value === 'LATE_RETURN')) {
            typeSelect.value = 'LATE_RETURN';
        }
        syncStatusOptions();
        if (statusSelect && [...statusSelect.options].some(option => option.value === 'OVERDUE' && !option.disabled)) {
            statusSelect.value = 'OVERDUE';
        }
    } else if (focus === 'late') {
        if (typeSelect && [...typeSelect.options].some(option => option.value === 'LATE_RETURN')) {
            typeSelect.value = 'LATE_RETURN';
        }
        if (statusSelect) statusSelect.value = 'all';
        syncStatusOptions();
    } else if (focus === 'restrictions') {
        if (typeSelect && [...typeSelect.options].some(option => option.value === 'RESTRICTION')) {
            typeSelect.value = 'RESTRICTION';
        }
        if (statusSelect) statusSelect.value = 'all';
        syncStatusOptions();
    } else {
        syncStatusOptions();
    }

    search?.addEventListener('input', filterCases);
    typeSelect?.addEventListener('change', () => {
        syncStatusOptions();
        filterCases();
    });
    statusSelect?.addEventListener('change', filterCases);

    filterCases();
})();
</script>

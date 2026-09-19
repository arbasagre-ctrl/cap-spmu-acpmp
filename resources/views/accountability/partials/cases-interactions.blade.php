<script>
/*
 * One row, one toggle: works for any [data-case-toggle] button anywhere on
 * the page (Current Accountability cases and Accountability History rows
 * alike), independent of whether the cases table's own filter toolbar exists
 * on this page load. Delegated on document so it also covers rows that sit
 * later in the DOM than this script tag - such as the History table below.
 */
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
    const search = document.getElementById('accountability-case-search');
    const toggle = document.getElementById('accountability-filter-toggle');
    const menu = document.getElementById('accountability-filter-menu');
    const body = document.getElementById('accountability-cases-body');
    const empty = document.getElementById('accountability-cases-none');
    if (!search || !toggle || !menu || !body || !empty) return;

    const rows = [...body.querySelectorAll('[data-case]')];
    const filters = [...menu.querySelectorAll('input[type="checkbox"]')];
    const typeChips = [...document.querySelectorAll('.accountability-type-chip')];

    function selectedType() {
        return typeChips.find(chip => chip.classList.contains('is-active'))?.dataset.typeFilter ?? 'all';
    }

    /*
     * Every rendered case status has a checkbox, so the selected set narrows
     * Property, Late Return, Billing, and standalone Restriction rows alike.
     * The Restrictions chip instead selects rows by their restriction flag;
     * a Property or Late Return case keeps its real case type.
     *
     * Cases are collapsed by default and only expand from their own action
     * button (see the data-case-toggle handler below). Filtering must never
     * force a detail row open - it only ever forces one shut, when its
     * summary row no longer matches and so cannot be seen at all.
     */
    function filterCases() {
        const query = search.value.trim().toLocaleLowerCase();
        const statuses = new Set(filters.filter(input => input.checked).map(input => input.value));
        const type = selectedType();
        let visible = 0;
        rows.forEach(row => {
            const searchMatches = row.dataset.search.toLocaleLowerCase().includes(query);
            const statusMatches = statuses.has(row.dataset.status);
            const typeMatches = type === 'all'
                || (type === 'RESTRICTION'
                    ? row.dataset.hasRestriction === '1'
                    : row.dataset.caseType === type);
            const matches = searchMatches && statusMatches && typeMatches;
            row.hidden = !matches;
            const detail = row.nextElementSibling;
            if (detail?.classList.contains('accountability-case-detail-row')) {
                detail.hidden = !matches || detail.dataset.expanded !== 'true';
            }
            if (matches) visible++;
        });
        empty.hidden = visible > 0;
    }

    typeChips.forEach(chip => {
        chip.addEventListener('click', () => {
            typeChips.forEach(other => other.classList.toggle('is-active', other === chip));
            filterCases();
        });
    });
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
        /* A focus hint is only ever about a late-return status, so narrow the type too. */
        const lateReturnChip = typeChips.find(chip => chip.dataset.typeFilter === 'LATE_RETURN');
        if (lateReturnChip) typeChips.forEach(chip => chip.classList.toggle('is-active', chip === lateReturnChip));
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

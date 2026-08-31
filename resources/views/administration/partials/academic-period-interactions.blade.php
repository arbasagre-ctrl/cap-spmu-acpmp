<script>
(() => {
    const initializeAcademicPeriods = () => {
        const body = document.getElementById('academic-periods-body');

        if (!body) {
            return;
        }

        const rows = Array.from(body.querySelectorAll('[data-period-row]'));
        const search = document.getElementById('academic-periods-search');
        const sort = document.getElementById('academic-periods-sort');
        const emptyRow = body.querySelector('[data-period-empty]');
        const noResults = body.querySelector('[data-period-no-results]');

        if (rows.length === 0) {
            return;
        }

        const render = () => {
            const query = (search?.value || '').trim().toLowerCase();
            const oldestFirst = sort?.value === 'oldest';

            const ordered = [...rows].sort((left, right) => {
                const difference =
                    Number(left.dataset.periodStart || 0) - Number(right.dataset.periodStart || 0);

                return oldestFirst ? difference : -difference;
            });

            ordered.forEach((row) => body.appendChild(row));

            let visible = 0;

            rows.forEach((row) => {
                const matches = !query || (row.dataset.periodSearch || '').includes(query);
                row.hidden = !matches;

                if (matches) {
                    visible += 1;
                }
            });

            if (noResults) {
                noResults.hidden = visible !== 0;
                body.appendChild(noResults);
            }

            if (emptyRow) {
                emptyRow.hidden = true;
            }
        };

        search?.addEventListener('input', render);
        sort?.addEventListener('change', render);

        render();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAcademicPeriods, { once: true });
    } else {
        initializeAcademicPeriods();
    }
})();
</script>

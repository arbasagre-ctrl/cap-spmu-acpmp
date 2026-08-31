<script>
document.addEventListener('DOMContentLoaded', () => {
    const list = document.getElementById('borrower-request-list');

    if (!list) {
        return;
    }

    const searchInput = document.getElementById('request-search');
    const statusFilter = document.getElementById('request-status-filter');
    const sortSelect = document.getElementById('request-sort');
    const cards = Array.from(list.querySelectorAll('[data-request-card]'));
    const countLabel = document.querySelector('[data-request-count]');
    const emptyState = document.getElementById('request-filter-empty');
    const resetButton = document.getElementById('request-filter-reset');

    /*
     * Search, status, and sort operate on the current page of records.
     * Pagination itself stays server-side.
     */
    const applyFilters = () => {
        const query = (searchInput?.value || '').trim().toLowerCase();
        const status = statusFilter?.value || 'all';

        if (sortSelect) {
            const direction = sortSelect.value === 'oldest' ? -1 : 1;

            [...cards]
                .sort((a, b) => (Number(b.dataset.submitted) - Number(a.dataset.submitted)) * direction)
                .forEach((card) => list.appendChild(card));
        }

        let visible = 0;

        cards.forEach((card) => {
            const matchesSearch = !query || (card.dataset.search || '').includes(query);
            const matchesStatus = status === 'all' || card.dataset.statusGroup === status;
            const show = matchesSearch && matchesStatus;

            card.hidden = !show;

            if (show) {
                visible++;
            }
        });

        if (countLabel) {
            /*
             * Unfiltered, the count reflects every record across all pages.
             * Once a search or status filter is applied it reports the
             * matches on the page currently in view.
             */
            const unfiltered = !query && status === 'all';
            const shown = unfiltered ? Number(countLabel.dataset.total) : visible;

            countLabel.textContent = shown + (shown === 1 ? ' request found' : ' requests found');
        }

        if (emptyState) {
            emptyState.hidden = visible !== 0;
        }
    };

    searchInput?.addEventListener('input', applyFilters);
    statusFilter?.addEventListener('change', applyFilters);
    sortSelect?.addEventListener('change', applyFilters);

    resetButton?.addEventListener('click', () => {
        if (searchInput) {
            searchInput.value = '';
        }

        if (statusFilter) {
            statusFilter.value = 'all';
        }

        applyFilters();
        searchInput?.focus();
    });

    applyFilters();

    /* Row overflow menus */
    const closeMenus = (except = null) => {
        document.querySelectorAll('[data-request-menu]').forEach((menu) => {
            if (menu === except) {
                return;
            }

            menu.querySelector('[data-request-menu-panel]').hidden = true;
            menu.querySelector('[data-request-menu-trigger]').setAttribute('aria-expanded', 'false');
        });
    };

    document.querySelectorAll('[data-request-menu]').forEach((menu) => {
        const trigger = menu.querySelector('[data-request-menu-trigger]');
        const panel = menu.querySelector('[data-request-menu-panel]');

        trigger.addEventListener('click', (event) => {
            event.stopPropagation();

            const willOpen = panel.hidden;

            closeMenus(menu);
            panel.hidden = !willOpen;
            trigger.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    document.addEventListener('click', () => closeMenus());

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMenus();
        }
    });

    document.querySelectorAll('[data-copy-reference]').forEach((button) => {
        button.addEventListener('click', async () => {
            const reference = button.dataset.copyReference;
            const original = button.textContent;

            try {
                await navigator.clipboard.writeText(reference);
                button.textContent = 'Copied';
            } catch (error) {
                button.textContent = reference;
            }

            setTimeout(() => {
                button.textContent = original;
                closeMenus();
            }, 1100);
        });
    });
});
</script>

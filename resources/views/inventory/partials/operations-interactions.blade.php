<script>
(() => {
    const initializeSpmuInventoryBrowser = () => {
        const browser = document.querySelector('[data-spmu-inventory]');

        if (!browser) {
            return;
        }

        const rows = Array.from(browser.querySelectorAll('[data-spmu-inventory-row]'));
        const staticEmptyRow = browser.querySelector('[data-spmu-static-empty-row]');
        const search = document.getElementById('spmu-inventory-search');
        const category = document.getElementById('spmu-inventory-category');
        const noResults = document.getElementById('spmu-inventory-no-results');
        const footer = document.getElementById('spmu-inventory-footer');
        const pagination = document.getElementById('spmu-inventory-pagination');
        const summaries = Array.from(browser.querySelectorAll('[data-spmu-inventory-summary]'));
        const pageSizes = Array.from(browser.querySelectorAll('[data-spmu-inventory-page-size]'));

        let currentPage = 1;

        const perPage = () => {
            const value = Number(pageSizes[0]?.value || 15);

            return Number.isFinite(value) && value > 0 ? value : 15;
        };

        const matchingRows = () => {
            const query = (search?.value || '').trim().toLowerCase();
            const selected = (category?.value || '').trim().toLowerCase();

            return rows.filter((row) => {
                const matchesSearch = !query || (row.dataset.search || '').includes(query);
                const matchesCategory = !selected || (row.dataset.category || '') === selected;

                return matchesSearch && matchesCategory;
            });
        };

        const pageNumbers = (totalPages, page) => {
            if (totalPages <= 7) {
                return Array.from({ length: totalPages }, (unused, index) => index + 1);
            }

            const pages = new Set([1, totalPages, page]);

            [page - 1, page + 1].forEach((candidate) => {
                if (candidate > 1 && candidate < totalPages) {
                    pages.add(candidate);
                }
            });

            const ordered = [...pages].sort((left, right) => left - right);
            const withGaps = [];

            ordered.forEach((value, index) => {
                if (index > 0 && value - ordered[index - 1] > 1) {
                    withGaps.push('…');
                }

                withGaps.push(value);
            });

            return withGaps;
        };

        const renderPagination = (totalPages) => {
            if (!pagination) {
                return;
            }

            pagination.textContent = '';

            const chevron = (previous) =>
                `<svg class="ui-icon${previous ? ' spmu-inventory-page-previous' : ''}" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6" /></svg>`;

            const addPageButton = (page, options = {}) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'spmu-inventory-page';

                if (options.active) {
                    button.classList.add('is-active');
                    button.setAttribute('aria-current', 'page');
                }

                if (options.disabled) {
                    button.disabled = true;
                }

                button.setAttribute('aria-label', options.ariaLabel || `Page ${page}`);
                button.innerHTML = options.html ?? String(page);

                button.addEventListener('click', () => {
                    currentPage = page;
                    render();
                    browser.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });

                pagination.appendChild(button);
            };

            addPageButton(Math.max(1, currentPage - 1), {
                disabled: currentPage <= 1,
                html: `${chevron(true)}<span>Previous</span>`,
                ariaLabel: 'Previous page',
            });

            if (browser.dataset.paginationMode !== 'simple') {
                pageNumbers(totalPages, currentPage).forEach((entry) => {
                    if (entry === '…') {
                        const gap = document.createElement('span');
                        gap.className = 'spmu-inventory-page-ellipsis';
                        gap.setAttribute('aria-hidden', 'true');
                        gap.textContent = '…';
                        pagination.appendChild(gap);

                        return;
                    }

                    addPageButton(entry, { active: entry === currentPage });
                });
            }

            addPageButton(Math.min(totalPages, currentPage + 1), {
                disabled: currentPage >= totalPages,
                html: `<span>Next</span>${chevron(false)}`,
                ariaLabel: 'Next page',
            });
        };

        const render = () => {
            const matches = matchingRows();
            const size = perPage();
            const totalPages = Math.max(1, Math.ceil(matches.length / size));

            currentPage = Math.min(Math.max(1, currentPage), totalPages);

            const firstIndex = (currentPage - 1) * size;
            const lastIndex = Math.min(firstIndex + size, matches.length);

            rows.forEach((row) => {
                row.hidden = true;
            });

            matches.slice(firstIndex, lastIndex).forEach((row) => {
                row.hidden = false;
            });

            if (staticEmptyRow) {
                staticEmptyRow.hidden = rows.length > 0;
            }

            if (noResults) {
                noResults.hidden = rows.length === 0 || matches.length !== 0;
            }

            if (footer) {
                footer.hidden = matches.length === 0;
            }

            renderPagination(totalPages);

            summaries.forEach((summary) => {
                summary.textContent = matches.length === 0
                    ? (rows.length === 0 ? 'No inventory items' : 'No matching inventory items')
                    : `Showing ${firstIndex + 1}–${lastIndex} of ${matches.length} inventory item${matches.length === 1 ? '' : 's'}`;
            });
        };

        search?.addEventListener('input', () => {
            currentPage = 1;
            render();
        });

        category?.addEventListener('change', () => {
            currentPage = 1;
            render();
        });

        // Keep page-size controls in step when a workspace shows both copies.
        pageSizes.forEach((control) => {
            control.addEventListener('change', () => {
                pageSizes.forEach((other) => {
                    other.value = control.value;
                });

                currentPage = 1;
                render();
            });
        });

        render();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeSpmuInventoryBrowser, { once: true });
    } else {
        initializeSpmuInventoryBrowser();
    }
})();
</script>

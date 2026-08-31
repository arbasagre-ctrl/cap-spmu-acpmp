<script>
(() => {
    const initializeBorrowerInventoryBrowser = () => {
        const browser = document.querySelector('[data-borrower-inventory]');

        if (!browser) {
            return;
        }

        const rows = Array.from(browser.querySelectorAll('[data-borrower-inventory-row]'));
        const staticEmptyRow = browser.querySelector('[data-static-empty-row]');
        const search = document.getElementById('borrower-inventory-search');
        const category = document.getElementById('borrower-inventory-category');
        const noResults = document.getElementById('borrower-inventory-no-results');
        const footer = document.getElementById('borrower-inventory-footer');
        const pagination = document.getElementById('borrower-inventory-pagination');
        const summaries = Array.from(browser.querySelectorAll('[data-borrower-inventory-summary]'));
        const pageSizes = Array.from(browser.querySelectorAll('[data-borrower-inventory-page-size]'));

        let currentPage = 1;

        const perPage = () => {
            const value = Number(pageSizes[0]?.value || 7);

            return Number.isFinite(value) && value > 0 ? value : 7;
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
                `<svg class="ui-icon${previous ? ' borrower-inventory-page-previous' : ''}" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6" /></svg>`;

            const addPageButton = (page, options = {}) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'borrower-inventory-page';

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

            pageNumbers(totalPages, currentPage).forEach((entry) => {
                if (entry === '…') {
                    const gap = document.createElement('span');
                    gap.className = 'borrower-inventory-page-ellipsis';
                    gap.setAttribute('aria-hidden', 'true');
                    gap.textContent = '…';
                    pagination.appendChild(gap);

                    return;
                }

                addPageButton(entry, { active: entry === currentPage });
            });

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

            // Row numbers follow the visible page, not the underlying order.
            matches.slice(firstIndex, lastIndex).forEach((row, index) => {
                row.hidden = false;

                const number = row.querySelector('[data-row-number]');

                if (number) {
                    number.textContent = String(firstIndex + index + 1);
                }
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
                    ? (rows.length === 0 ? 'No available items' : 'No matching available items')
                    : `Showing ${firstIndex + 1}–${lastIndex} of ${matches.length} available item${matches.length === 1 ? '' : 's'}`;
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

        // The page-size control appears above and below the table; keep both
        // in step so either one drives the same pagination state.
        pageSizes.forEach((control) => {
            control.addEventListener('change', () => {
                pageSizes.forEach((other) => {
                    other.value = control.value;
                });

                currentPage = 1;
                render();
            });
        });

        browser.querySelectorAll('[data-description-toggle]').forEach((button) => {
            button.addEventListener('click', () => {
                const holder = button.closest('[data-description]');
                const preview = holder?.querySelector('[data-description-preview]');
                const full = holder?.querySelector('[data-description-full]');

                if (!preview || !full) {
                    return;
                }

                const expanded = button.getAttribute('aria-expanded') === 'true';

                preview.hidden = !expanded;
                full.hidden = expanded;
                button.setAttribute('aria-expanded', String(!expanded));
                button.textContent = expanded ? 'More' : 'Less';
            });
        });

        render();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeBorrowerInventoryBrowser, { once: true });
    } else {
        initializeBorrowerInventoryBrowser();
    }
})();
</script>

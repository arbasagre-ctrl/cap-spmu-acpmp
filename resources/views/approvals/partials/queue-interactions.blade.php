<script>
(() => {
    const initializeApprovalQueue = () => {
        const body = document.getElementById('approval-queue-body');

        if (!body) {
            return;
        }

        const records = Array.from(body.querySelectorAll('[data-approval-record]'));
        const search = document.getElementById('approval-queue-search');
        const sort = document.getElementById('approval-queue-sort');
        const noResults = document.getElementById('approval-queue-no-results');
        const footer = document.getElementById('approval-queue-footer');
        const summary = document.getElementById('approval-queue-summary');
        const pagination = document.getElementById('approval-queue-pagination');
        const perPage = 5;

        let currentPage = 1;

        const sortRecords = () => {
            const newestFirst = sort?.value === 'newest';

            const ordered = [...records].sort((left, right) => {
                const difference =
                    Number(left.dataset.created || 0) - Number(right.dataset.created || 0);

                return newestFirst ? -difference : difference;
            });

            ordered.forEach((record) => body.appendChild(record));

            return ordered;
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
                `<svg class="ui-icon${previous ? ' approval-queue-page-previous' : ''}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6" /></svg>`;

            const addPageButton = (label, page, options = {}) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'approval-queue-page';

                if (options.active) {
                    button.classList.add('is-active');
                    button.setAttribute('aria-current', 'page');
                }

                if (options.disabled) {
                    button.disabled = true;
                }

                button.setAttribute('aria-label', options.ariaLabel || `Page ${label}`);

                if (options.icon) {
                    button.innerHTML = options.icon;
                } else {
                    button.textContent = String(label);
                }

                button.addEventListener('click', () => {
                    currentPage = page;
                    render();
                });

                pagination.appendChild(button);
            };

            addPageButton('', Math.max(1, currentPage - 1), {
                disabled: currentPage <= 1,
                icon: chevron(true),
                ariaLabel: 'Previous page',
            });

            pageNumbers(totalPages, currentPage).forEach((entry) => {
                if (entry === '…') {
                    const gap = document.createElement('span');
                    gap.className = 'approval-queue-page-ellipsis';
                    gap.setAttribute('aria-hidden', 'true');
                    gap.textContent = '…';
                    pagination.appendChild(gap);

                    return;
                }

                addPageButton(entry, entry, { active: entry === currentPage });
            });

            addPageButton('', Math.min(totalPages, currentPage + 1), {
                disabled: currentPage >= totalPages,
                icon: chevron(false),
                ariaLabel: 'Next page',
            });
        };

        const render = () => {
            const query = (search?.value || '').trim().toLowerCase();
            const ordered = sortRecords();
            const matched = [];

            ordered.forEach((record) => {
                if (!query || (record.dataset.search || '').includes(query)) {
                    matched.push(record);
                } else {
                    record.hidden = true;
                }
            });

            const totalPages = Math.max(1, Math.ceil(matched.length / perPage));

            currentPage = Math.min(Math.max(1, currentPage), totalPages);

            const firstIndex = (currentPage - 1) * perPage;
            const lastIndex = Math.min(firstIndex + perPage, matched.length);

            matched.forEach((record, index) => {
                record.hidden = index < firstIndex || index >= lastIndex;
            });

            if (noResults) {
                noResults.hidden = matched.length !== 0;
            }

            if (footer) {
                footer.hidden = matched.length === 0;
            }

            renderPagination(totalPages);

            if (summary) {
                summary.textContent = `Showing ${firstIndex + 1} to ${lastIndex} of ${matched.length} request${matched.length === 1 ? '' : 's'}`;
            }
        };

        search?.addEventListener('input', () => {
            currentPage = 1;
            render();
        });

        sort?.addEventListener('change', () => {
            currentPage = 1;
            render();
        });

        render();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeApprovalQueue, { once: true });
    } else {
        initializeApprovalQueue();
    }
})();
</script>

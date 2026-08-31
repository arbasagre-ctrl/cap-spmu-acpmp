<script>
(() => {
    const initializeCustodyOversight = () => {
        const workspace = document.querySelector('[data-custody-oversight]');
        const list = document.getElementById('custody-oversight-list');

        if (!workspace || !list) {
            return;
        }

        const records = Array.from(list.querySelectorAll('[data-custody-record]'));
        const tabs = Array.from(workspace.querySelectorAll('[data-custody-tab]'));
        const search = document.getElementById('custody-oversight-search');
        const from = document.getElementById('custody-oversight-from');
        const to = document.getElementById('custody-oversight-to');
        const sort = document.getElementById('custody-oversight-sort');
        const clear = document.getElementById('custody-oversight-clear');
        const noResults = document.getElementById('custody-oversight-no-results');
        const summary = document.getElementById('custody-oversight-result-summary');
        const dateError = document.getElementById('custody-oversight-date-error');
        const footer = document.getElementById('custody-oversight-footer');
        const pageSize = document.getElementById('custody-oversight-page-size');
        const pagination = document.getElementById('custody-oversight-pagination');

        let activeTab = 'all';
        let currentPage = 1;

        const compareDateValues = (leftValue, rightValue, direction = 'asc') => {
            const leftMissing = !leftValue;
            const rightMissing = !rightValue;

            if (leftMissing && rightMissing) return 0;
            if (leftMissing) return 1;
            if (rightMissing) return -1;

            return direction === 'desc'
                ? rightValue.localeCompare(leftValue)
                : leftValue.localeCompare(rightValue);
        };

        const sortRecords = () => {
            const mode = sort?.value || 'return-soonest';

            const ordered = [...records].sort((left, right) => {
                const leftCompleted = left.dataset.custodyGroup === 'completed';
                const rightCompleted = right.dataset.custodyGroup === 'completed';

                // Keep completed records after active operational work unless
                // the user explicitly opens the Completed tab.
                if (activeTab !== 'completed' && leftCompleted !== rightCompleted) {
                    return leftCompleted ? 1 : -1;
                }

                if (mode === 'return-soonest') {
                    const difference = compareDateValues(
                        left.dataset.return || '',
                        right.dataset.return || ''
                    );
                    if (difference !== 0) return difference;
                }

                if (mode === 'pickup-soonest') {
                    const difference = compareDateValues(
                        left.dataset.pickup || '',
                        right.dataset.pickup || ''
                    );
                    if (difference !== 0) return difference;
                }

                if (mode === 'newest') {
                    return Number(right.dataset.created || 0) - Number(left.dataset.created || 0);
                }

                if (mode === 'oldest') {
                    return Number(left.dataset.created || 0) - Number(right.dataset.created || 0);
                }

                const priorityDifference =
                    Number(left.dataset.custodyPriority || 99)
                    - Number(right.dataset.custodyPriority || 99);

                if (priorityDifference !== 0) return priorityDifference;

                return Number(right.dataset.created || 0) - Number(left.dataset.created || 0);
            });

            ordered.forEach((record) => list.appendChild(record));

            return ordered;
        };

        const matchesTab = (record) => {
            const group = record.dataset.custodyGroup || 'active';

            if (activeTab === 'all') {
                return true;
            }

            if (activeTab === 'active') {
                return group !== 'completed';
            }

            return group === activeTab;
        };

        const perPage = () => {
            const value = Number(pageSize?.value || 5);

            return Number.isFinite(value) && value > 0 ? value : 5;
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

            const addPageButton = (label, page, options = {}) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'custody-oversight-page';

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

            const chevron = (previous) =>
                `<svg class="ui-icon${previous ? ' custody-oversight-page-previous' : ''}" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6" /></svg>`;

            addPageButton('', Math.max(1, currentPage - 1), {
                disabled: currentPage <= 1,
                icon: chevron(true),
                ariaLabel: 'Previous page',
            });

            pageNumbers(totalPages, currentPage).forEach((entry) => {
                if (entry === '…') {
                    const gap = document.createElement('span');
                    gap.className = 'custody-oversight-page-ellipsis';
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
            const fromDate = from?.value || '';
            const toDate = to?.value || '';

            const invalidDateRange = Boolean(fromDate && toDate && fromDate > toDate);

            from?.classList.toggle('is-invalid', invalidDateRange);
            to?.classList.toggle('is-invalid', invalidDateRange);

            if (dateError) {
                dateError.hidden = !invalidDateRange;
            }

            const ordered = sortRecords();
            const matched = [];

            ordered.forEach((record) => {
                const recordSearch = record.dataset.search || '';
                const relevantDates = (record.dataset.dates || '').split(',').filter(Boolean);

                const searchMatches = !query || recordSearch.includes(query);

                // The neutral Date From/To filter matches any operational date
                // on the record: schedule, pickup, issue, return, or close.
                const dateMatches =
                    invalidDateRange
                    || (!fromDate && !toDate)
                    || relevantDates.some((date) =>
                        (!fromDate || date >= fromDate)
                        && (!toDate || date <= toDate)
                    );

                if (matchesTab(record) && searchMatches && dateMatches) {
                    matched.push(record);
                } else {
                    record.hidden = true;
                }
            });

            const size = perPage();
            const totalPages = Math.max(1, Math.ceil(matched.length / size));

            currentPage = Math.min(Math.max(1, currentPage), totalPages);

            const firstIndex = (currentPage - 1) * size;
            const lastIndex = Math.min(firstIndex + size, matched.length);

            matched.forEach((record, index) => {
                record.hidden = index < firstIndex || index >= lastIndex;
            });

            tabs.forEach((tab) => {
                const selected = tab.dataset.custodyTab === activeTab;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });

            if (noResults) {
                noResults.hidden = matched.length !== 0 || records.length === 0;
            }

            if (footer) {
                footer.hidden = matched.length === 0;
            }

            renderPagination(totalPages);

            if (summary) {
                const searchSuffix = query ? ` matching "${search.value.trim()}"` : '';

                summary.textContent = matched.length === 0
                    ? `No transactions to display${searchSuffix}.`
                    : `Showing ${firstIndex + 1} to ${lastIndex} of ${matched.length} transaction${matched.length === 1 ? '' : 's'}${searchSuffix}.`;
            }
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                activeTab = tab.dataset.custodyTab || 'all';
                currentPage = 1;
                render();
            });
        });

        search?.addEventListener('input', () => {
            activeTab = 'all';
            currentPage = 1;
            render();
        });

        [from, to, sort].forEach((control) => {
            control?.addEventListener('change', () => {
                currentPage = 1;
                render();
            });
        });

        pageSize?.addEventListener('change', () => {
            currentPage = 1;
            render();
        });

        clear?.addEventListener('click', () => {
            if (search) search.value = '';
            if (from) from.value = '';
            if (to) to.value = '';
            if (sort) sort.value = 'return-soonest';

            activeTab = 'all';
            currentPage = 1;
            render();
        });

        render();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeCustodyOversight, { once: true });
    } else {
        initializeCustodyOversight();
    }
})();
</script>

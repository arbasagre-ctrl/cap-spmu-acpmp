{{--
    Borrower filter.

    Cascading, not global: Reporting Period -> Division -> Office / Unit ->
    Borrower. Options are fetched from this same route with borrower_options=1,
    scoped to the upstream filters already chosen, so "All borrowers" always
    means every borrower who actually filed a request in that scope - never
    every user in the system.

    Selecting a borrower resubmits the filter form exactly like the other
    Analytics filters, so the whole page re-scopes to that borrower in one
    request instead of a second client-side fetch.
--}}
<div class="analytics-filter-field analytics-borrower-field" data-borrower-picker>
    <span class="analytics-filter-label">Borrower</span>
    <input type="hidden" name="borrower" value="{{ $selectedBorrower ?? '' }}" data-borrower-value>

    <button
        type="button"
        class="analytics-borrower-trigger"
        data-borrower-trigger
        aria-haspopup="listbox"
        aria-expanded="false"
    >
        <span data-borrower-current>{{ $selectedBorrowerLabel ?? 'All borrowers' }}</span>
        <svg
            class="analytics-borrower-chevron"
            width="14"
            height="14"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            <path d="m7 10 5 5 5-5" />
        </svg>
    </button>

    <div class="analytics-borrower-menu" data-borrower-menu hidden>
        <label class="analytics-borrower-search-wrap">
            <span class="sr-only">Search borrower</span>
            <input
                type="search"
                class="analytics-borrower-search"
                placeholder="Search borrower by name or email..."
                autocomplete="off"
                data-borrower-search
            >
        </label>

        <div class="analytics-borrower-options" role="listbox" data-borrower-options>
            <button
                type="button"
                class="analytics-borrower-option{{ ($selectedBorrower ?? null) === null ? ' is-selected' : '' }}"
                data-borrower-option
                data-value=""
                data-name="All borrowers"
                role="option"
                aria-selected="{{ ($selectedBorrower ?? null) === null ? 'true' : 'false' }}"
            >
                <strong>All borrowers</strong>
            </button>
        </div>

        <p class="analytics-borrower-message" data-borrower-message hidden></p>
    </div>
</div>

<style>
.analytics-borrower-field { position: relative; min-width: 0; }

.analytics-borrower-trigger,
button.analytics-borrower-trigger {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 10px !important;
    width: 100% !important;
    min-height: 44px !important;
    padding: 0 !important;
    color: var(--heading) !important;
    background: transparent !important;
    border: 1px solid transparent !important;
    border-radius: 9px !important;
    font: inherit !important;
    font-size: 12.5px !important;
    font-weight: 650 !important;
    line-height: 1.2 !important;
    text-align: left !important;
    cursor: pointer;
    box-shadow: none !important;
    transform: none !important;
    translate: none !important;
}

.analytics-borrower-trigger > [data-borrower-current] {
    flex: 1 1 auto;
    min-width: 0;
    text-align: left;
}

/* Same visual treatment as Reporting Period / Division / Office / Unit. */
.analytics-borrower-trigger:hover,
button.analytics-borrower-trigger:hover {
    color: var(--heading) !important;
    background-color: var(--surface-subtle) !important;
    border-color: transparent !important;
    box-shadow: none !important;
}

.analytics-borrower-trigger:focus,
.analytics-borrower-trigger:focus-visible,
.analytics-borrower-field.is-open .analytics-borrower-trigger,
button.analytics-borrower-trigger:focus,
button.analytics-borrower-trigger:focus-visible,
.analytics-borrower-field.is-open button.analytics-borrower-trigger {
    padding-left: 9px !important;
    color: var(--heading) !important;
    border-color: var(--interactive) !important;
    background-color: var(--input-bg) !important;
    outline: none !important;
    box-shadow: none !important;
}

.analytics-borrower-trigger:active,
button.analytics-borrower-trigger:active {
    transform: none !important;
    translate: none !important;
    box-shadow: none !important;
}

.analytics-borrower-chevron {
    flex: 0 0 14px;
    width: 14px;
    height: 14px;
    pointer-events: none;
}

.analytics-borrower-menu {
    position: absolute;
    z-index: 40;
    top: calc(100% + 6px);
    left: 0;
    /* Matches the Borrower field's own width exactly, at every breakpoint. */
    width: 100%;
    padding: 8px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--surface-elevated);
    box-shadow: 0 12px 30px rgba(15, 35, 58, .16);
    overflow: hidden;
}

.analytics-borrower-search-wrap { display: block; margin: 0 0 7px; }

.analytics-borrower-search {
    width: 100%;
    min-height: 36px;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--surface-elevated);
    color: var(--heading);
    font: inherit;
    font-size: 12.5px;
    box-shadow: none;
}

.analytics-borrower-options {
    display: block;
    max-height: 260px;
    overflow-y: auto;
    overscroll-behavior: contain;
    border-top: 1px solid var(--border);
}

.analytics-borrower-option,
button.analytics-borrower-option {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    gap: 12px !important;
    width: 100% !important;
    min-height: 0 !important;
    margin: 0 !important;
    padding: 9px 8px !important;
    border: 0 !important;
    border-bottom: 1px solid color-mix(in srgb, var(--border) 72%, transparent) !important;
    border-radius: 0 !important;
    background: transparent !important;
    color: var(--heading) !important;
    text-align: left !important;
    cursor: pointer;
    box-shadow: none !important;
    appearance: none;
}

.analytics-borrower-option:last-child, button.analytics-borrower-option:last-child { border-bottom: 0 !important; }

.analytics-borrower-option:hover,
.analytics-borrower-option:focus-visible,
button.analytics-borrower-option:hover,
button.analytics-borrower-option:focus-visible {
    background: var(--surface-subtle) !important;
    border-radius: 0 !important;
    outline: none !important;
    box-shadow: none !important;
}

.analytics-borrower-option.is-selected,
button.analytics-borrower-option.is-selected {
    background: color-mix(in srgb, var(--info-bg) 55%, transparent) !important;
}

.analytics-borrower-option strong {
    min-width: 0;
    font-size: 12.5px;
    font-weight: 700;
    line-height: 1.35;
}

.analytics-borrower-option small {
    flex: 0 1 auto;
    max-width: 55%;
    color: var(--text-muted);
    font-size: 10.5px;
    line-height: 1.35;
    text-align: right;
    overflow-wrap: anywhere;
}

.analytics-borrower-message {
    margin: 7px 4px 2px;
    color: var(--text-muted);
    font-size: 11px;
    line-height: 1.4;
}

.analytics-borrower-field[aria-busy="true"] .analytics-borrower-trigger { cursor: progress; }

@media (max-width: 620px) {
    .analytics-borrower-menu { min-width: 0; width: 100%; }
}
</style>

<script>
(() => {
    const picker = document.querySelector('[data-borrower-picker]');
    if (!picker) return;

    const form = picker.closest('form');
    const period = document.getElementById('analytics-period');
    const division = document.getElementById('analytics-group');
    const unit = document.getElementById('analytics-unit');
    const value = picker.querySelector('[data-borrower-value]');
    const trigger = picker.querySelector('[data-borrower-trigger]');
    const current = picker.querySelector('[data-borrower-current]');
    const menu = picker.querySelector('[data-borrower-menu]');
    const search = picker.querySelector('[data-borrower-search]');
    const options = picker.querySelector('[data-borrower-options]');
    const message = picker.querySelector('[data-borrower-message]');

    let controller = null;
    let searchTimer = null;

    const closeMenu = () => {
        if (menu) menu.hidden = true;
        picker.classList.remove('is-open');
        trigger?.setAttribute('aria-expanded', 'false');
    };

    const scopeUrl = (query = '') => {
        const params = new URLSearchParams();
        params.set('borrower_options', '1');
        params.set('academic_period', period?.value || 'month');
        if (division?.value && division.value !== 'all') params.set('group', division.value);
        if (unit?.value && unit.value !== 'all') params.set('unit', unit.value);
        if (query.trim() !== '') params.set('q', query.trim());
        if (value?.value) params.set('selected_borrower', value.value);

        const endpoint = new URL(window.location.pathname, window.location.origin);
        endpoint.search = params.toString();

        return endpoint.toString();
    };

    const makeOption = (item) => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'analytics-borrower-option';
        option.dataset.borrowerOption = '';
        option.dataset.value = String(item.value ?? '');
        option.dataset.name = item.name || 'Borrower';
        option.setAttribute('role', 'option');

        const name = document.createElement('strong');
        name.textContent = item.name || 'Borrower';
        option.appendChild(name);

        if (item.email) {
            const email = document.createElement('small');
            email.textContent = item.email;
            option.appendChild(email);
        }

        return option;
    };

    const renderOptions = (items, payload = {}, query = '') => {
        if (!options) return;

        options.innerHTML = '';

        const all = document.createElement('button');
        all.type = 'button';
        all.className = 'analytics-borrower-option';
        all.dataset.borrowerOption = '';
        all.dataset.value = '';
        all.dataset.name = 'All borrowers';
        all.setAttribute('role', 'option');
        const allLabel = document.createElement('strong');
        allLabel.textContent = 'All borrowers';
        all.appendChild(allLabel);
        options.appendChild(all);

        items.forEach((item) => options.appendChild(makeOption(item)));

        const selected = value?.value || '';

        options.querySelectorAll('[data-borrower-option]').forEach((option) => {
            const isSelected = option.dataset.value === selected;
            option.classList.toggle('is-selected', isSelected);
            option.setAttribute('aria-selected', isSelected ? 'true' : 'false');
        });

        if (!message) return;

        const trimmed = query.trim();

        if (items.length === 0 && trimmed !== '') {
            message.textContent = 'No borrower matches this search in the current scope.';
            message.hidden = false;
            return;
        }

        if (items.length === 0 && trimmed === '') {
            message.textContent = 'No borrowers are available in the current scope.';
            message.hidden = false;
            return;
        }

        if (payload.has_more) {
            message.textContent = `Showing the first ${payload.shown || items.length} of ${payload.count} matching borrowers. Search by name or email to find more.`;
            message.hidden = false;
            return;
        }

        message.hidden = true;
        message.textContent = '';
    };

    const refresh = async (query = '', open = false) => {
        controller?.abort();
        controller = new AbortController();
        picker.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(scopeUrl(query), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });

            if (!response.ok) {
                throw new Error(`Borrower scope request failed (${response.status})`);
            }

            const payload = await response.json();

            renderOptions(Array.isArray(payload.options) ? payload.options : [], payload, query);
        } catch (error) {
            if (error.name !== 'AbortError') {
                renderOptions([], {}, query);

                if (message) {
                    message.textContent = 'Borrower options could not be loaded. Please try again.';
                    message.hidden = false;
                }
            }
        } finally {
            picker.removeAttribute('aria-busy');

            if (open && menu) {
                menu.hidden = false;
                picker.classList.add('is-open');
                trigger?.setAttribute('aria-expanded', 'true');
                search?.focus();
            }
        }
    };

    trigger?.addEventListener('click', async () => {
        const opening = menu?.hidden !== false;

        if (!opening) {
            closeMenu();
            return;
        }

        if (search) search.value = '';

        await refresh('', true);
    });

    search?.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => refresh(search.value), 250);
    });

    options?.addEventListener('click', (event) => {
        const option = event.target.closest('[data-borrower-option]');
        if (!option) return;

        if (value) value.value = option.dataset.value || '';
        if (current) current.textContent = option.dataset.name || 'All borrowers';

        closeMenu();
        form?.submit();
    });

    document.addEventListener('click', (event) => {
        if (picker.contains(event.target)) return;
        closeMenu();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;

        closeMenu();
        trigger?.focus();
    });
})();
</script>

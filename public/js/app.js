(() => {
    const root = document.documentElement;
    const storageKey = root.dataset.themeStorageKey;
    const selectors = document.querySelectorAll('[data-appearance-select]');
    const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
    const allowedPreferences = ['light', 'dark', 'system'];

    if (!storageKey) {
        return;
    }

    const storedPreference = () => {
        const current = root.dataset.themePreference;
        return allowedPreferences.includes(current) ? current : 'light';
    };

    const resolvedTheme = (preference) => preference === 'dark' ? 'dark' : 'light';

    const readablePreference = (preference) => preference === 'system' ? 'Default' : `${preference[0].toUpperCase()}${preference.slice(1)}`;

    const updateControls = (preference) => {
        selectors.forEach((select) => {
            select.value = preference;
            const status = select.closest('.appearance-settings-card')?.querySelector('[data-appearance-status]');
            if (status) {
                status.textContent = preference === 'system'
                    ? 'Default theme is light.'
                    : `${readablePreference(preference)} mode is selected for this account on this browser.`;
            }
        });
    };

    const applyPreference = (preference, persist = false) => {
        if (!allowedPreferences.includes(preference)) {
            preference = 'system';
        }

        const theme = resolvedTheme(preference);
        root.dataset.themePreference = preference;
        root.dataset.theme = theme;
        root.style.colorScheme = theme;

        if (persist) {
            try {
                localStorage.setItem(storageKey, preference);
            } catch (_) {}
        }

        updateControls(preference);
    };

    selectors.forEach((select) => {
        select.addEventListener('change', () => applyPreference(select.value, true));
    });

    systemTheme.addEventListener('change', () => {
        // Default is now always light, so no need to reapply on system theme change
    });

    applyPreference(storedPreference());
})();

(() => {
    const sidebar = document.getElementById('primary-sidebar');
    const toggle = document.querySelector('[data-sidebar-toggle]');
    const closeControls = document.querySelectorAll('[data-sidebar-close]');
    const mobileSidebar = window.matchMedia('(max-width: 1049px)');

    if (!sidebar || !toggle) {
        return;
    }

    const setSidebarOpen = (open, restoreFocus = false) => {
        open = Boolean(open && mobileSidebar.matches);
        document.body.classList.toggle('sidebar-open', open);
        toggle.setAttribute('aria-expanded', String(open));

        if (open) {
            sidebar.querySelector('a, button')?.focus();
        } else if (restoreFocus) {
            toggle.focus();
        }
    };

    toggle.addEventListener('click', () => {
        if (mobileSidebar.matches) {
            setSidebarOpen(!document.body.classList.contains('sidebar-open'));
        }
    });

    closeControls.forEach((control) => {
        control.addEventListener('click', () => setSidebarOpen(false, true));
    });

    sidebar.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileSidebar.matches) {
                setSidebarOpen(false);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
            setSidebarOpen(false, true);
        }
    });

    mobileSidebar.addEventListener('change', () => {
        if (!mobileSidebar.matches) {
            setSidebarOpen(false);
        }
    });

    setSidebarOpen(false);
})();

(() => {
    const calendar = document.querySelector('[data-borrowing-calendar]');
    const preview = document.querySelector('[data-calendar-preview]');
    const previewBackdrop = document.querySelector('.calendar-preview-backdrop');

    if (!calendar || !preview || !previewBackdrop) {
        return;
    }

    const previewContent = preview.querySelector('[data-calendar-preview-content]');
    const previewClose = preview.querySelector('[data-calendar-preview-close]');
    const viewButtons = calendar.querySelectorAll('[data-calendar-view-button]');
    const viewPanels = calendar.querySelectorAll('[data-calendar-view-panel]');
    const phaseFilterControl = calendar.querySelector('[data-calendar-phase-filters]');
    const filterToggle = calendar.querySelector('[data-calendar-filter-toggle]');
    const filterPopover = calendar.querySelector('[data-calendar-filter-popover]');
    const phaseInputs = Array.from(calendar.querySelectorAll('[data-calendar-phase-filter]'));
    const phaseLive = calendar.querySelector('[data-calendar-phase-live]');
    const filterLabel = calendar.querySelector('[data-calendar-filter-label]');
    const listSearch = calendar.querySelector('[data-calendar-list-search]');
    const listStatus = calendar.querySelector('[data-calendar-list-status]');
    const listSort = calendar.querySelector('[data-calendar-list-sort]');
    const listLive = calendar.querySelector('[data-calendar-list-live]');
    const listRecords = calendar.querySelector('[data-calendar-list-records]');
    const filterEmpty = calendar.querySelector('[data-calendar-filter-empty]');
    const filterEmptyCopy = calendar.querySelector('[data-calendar-filter-empty-copy]');
    const defaultEmptyStates = calendar.querySelectorAll('[data-calendar-default-empty]');
    const compactViewport = window.matchMedia('(max-width: 700px)');
    let selectedPhaseCategory = ''; // empty means all activities
    let selectedListStatus = '';
    let selectedListQuery = '';
    let selectedListSort = 'date-soonest';
    let activeView = 'month';
    let lastTrigger = null;
    let closeTimer = null;

    const statusLabels = {
        active: 'Active',
        'due-soon': 'Due Soon',
        overdue: 'Overdue',
        returned: 'Returned',
    };

    const ownRecordInScope = (eventTrigger) => {
        const ownOnly = calendar.dataset.calendarFilterOwnOnly === 'true';
        return !ownOnly || eventTrigger.dataset.calendarOwnRecord === 'true';
    };

    const eventMatchesPhase = (eventTrigger) => {
        if (!ownRecordInScope(eventTrigger)) {
            return false;
        }

        const categories = (eventTrigger.dataset.calendarPhaseCategories || 'pickup')
            .split(' ')
            .filter(Boolean);

        return !selectedPhaseCategory || categories.includes(selectedPhaseCategory);
    };

    const eventMatchesList = (eventTrigger) => {
        if (!ownRecordInScope(eventTrigger)) {
            return false;
        }

        const statuses = (eventTrigger.dataset.calendarFilterStatuses || '')
            .split(' ')
            .filter(Boolean);
        const searchable = (eventTrigger.dataset.calendarFilterSearch || '').toLowerCase();
        const statusMatches = !selectedListStatus || statuses.includes(selectedListStatus);
        const searchMatches = !selectedListQuery || searchable.includes(selectedListQuery);

        return statusMatches && searchMatches;
    };

    const updateEmptyState = (matchingCount, hasFilters, copy) => {
        if (filterEmpty) {
            filterEmpty.hidden = !hasFilters || matchingCount > 0;
        }
        if (filterEmptyCopy && copy) {
            filterEmptyCopy.textContent = copy;
        }
        defaultEmptyStates.forEach((emptyState) => {
            emptyState.hidden = hasFilters;
        });
    };

    const refreshMonthDays = () => {
        let matchingCount = 0;

        calendar.querySelectorAll('[data-calendar-day-events]').forEach((dayEvents) => {
            const occurrences = Array.from(dayEvents.querySelectorAll('[data-calendar-occurrence]'));
            const matching = occurrences.filter((occurrence) => {
                const eventTrigger = occurrence.querySelector('[data-calendar-event]');
                return eventTrigger && eventMatchesPhase(eventTrigger);
            });

            matchingCount += matching.length;
            occurrences.forEach((occurrence) => {
                occurrence.hidden = true;
            });
            matching.slice(0, 2).forEach((occurrence) => {
                occurrence.hidden = false;
            });

            const moreButton = dayEvents.querySelector('[data-calendar-day]');
            if (moreButton) {
                const remaining = Math.max(0, matching.length - 2);
                moreButton.hidden = remaining === 0;
                moreButton.textContent = `+${remaining} more`;
            }
        });

        const hasFilters = selectedPhaseCategory !== '';
        if (activeView === 'month') {
            updateEmptyState(matchingCount, hasFilters, 'Choose another activity type or All activities.');
        }

        const selectedButton = phaseInputs.find((button) => button.value === selectedPhaseCategory);
        const selectedText = selectedButton?.textContent.trim() || 'All activities';
        if (phaseLive) {
            phaseLive.textContent = selectedPhaseCategory
                ? `Showing ${selectedText} calendar activity.`
                : 'Showing all calendar activity types.';
        }
        if (filterLabel) {
            filterLabel.textContent = selectedText;
        }
        phaseInputs.forEach((button) => {
            button.classList.toggle('is-selected', button.value === selectedPhaseCategory);
        });
    };

    const closeFilterMenu = () => {
        if (!filterPopover || !filterToggle) {
            return;
        }
        filterPopover.hidden = true;
        filterToggle.setAttribute('aria-expanded', 'false');
    };

    const toggleFilterMenu = () => {
        if (!filterPopover || !filterToggle) {
            return;
        }
        const willOpen = filterPopover.hidden;
        filterPopover.hidden = !willOpen;
        filterToggle.setAttribute('aria-expanded', String(willOpen));
    };

    const sortListRecords = () => {
        if (!listRecords) {
            return;
        }

        const records = Array.from(listRecords.children).filter((child) => child.matches('[data-calendar-event]'));
        records.sort((left, right) => {
            const leftDate = Number(left.dataset.calendarSortDate || 0);
            const rightDate = Number(right.dataset.calendarSortDate || 0);
            return selectedListSort === 'date-latest' ? rightDate - leftDate : leftDate - rightDate;
        });
        records.forEach((record) => listRecords.appendChild(record));
    };

    const applyListFilters = () => {
        if (!listRecords) {
            return;
        }

        const records = Array.from(listRecords.children).filter((child) => child.matches('[data-calendar-event]'));
        records.forEach((eventTrigger) => {
            eventTrigger.hidden = !eventMatchesList(eventTrigger);
        });
        sortListRecords();

        const matchingCount = records.filter(eventMatchesList).length;
        const hasFilters = Boolean(selectedListStatus || selectedListQuery);
        if (activeView === 'list') {
            updateEmptyState(matchingCount, hasFilters, 'Adjust your search or status filter.');
        }

        if (listLive) {
            const statusText = selectedListStatus ? statusLabels[selectedListStatus] : 'all statuses';
            const searchText = selectedListQuery ? ` matching “${selectedListQuery}”` : '';
            listLive.textContent = `Showing ${matchingCount} calendar records for ${statusText}${searchText}.`;
        }
    };

    const selectView = (view) => {
        activeView = view;
        viewButtons.forEach((button) => {
            const selected = button.dataset.calendarViewButton === view;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        viewPanels.forEach((panel) => {
            panel.hidden = panel.dataset.calendarViewPanel !== view;
        });
        if (phaseFilterControl) {
            phaseFilterControl.hidden = view !== 'month';
        }
        closeFilterMenu();

        if (view === 'month') {
            refreshMonthDays();
        } else {
            applyListFilters();
        }
    };

    const filterPreviewMonthEvents = () => {
        if (!previewContent || activeView !== 'month') {
            return;
        }

        previewContent.querySelectorAll('[data-calendar-event]').forEach((eventTrigger) => {
            eventTrigger.hidden = !eventMatchesPhase(eventTrigger);
        });
    };

    const openPreview = (template, trigger) => {
        if (!template || !previewContent) {
            return;
        }

        window.clearTimeout(closeTimer);
        previewContent.replaceChildren(template.content.cloneNode(true));
        filterPreviewMonthEvents();
        if (!preview.contains(trigger)) {
            lastTrigger = trigger;
        }
        preview.hidden = false;
        previewBackdrop.hidden = false;
        preview.setAttribute('aria-hidden', 'false');
        document.body.classList.add('calendar-preview-open');

        window.requestAnimationFrame(() => {
            preview.classList.add('is-open');
            previewBackdrop.classList.add('is-open');
            previewClose?.focus();
        });
    };

    const closePreview = (restoreFocus = true) => {
        if (preview.hidden) {
            return;
        }

        preview.classList.remove('is-open');
        previewBackdrop.classList.remove('is-open');
        preview.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('calendar-preview-open');
        closeTimer = window.setTimeout(() => {
            preview.hidden = true;
            previewBackdrop.hidden = true;
            previewContent?.replaceChildren();
        }, 170);

        if (restoreFocus && lastTrigger?.isConnected) {
            lastTrigger.focus();
        }
    };

    const activateCalendarControl = (target) => {
        const eventTrigger = target.closest('[data-calendar-event]');
        if (eventTrigger && (calendar.contains(eventTrigger) || preview.contains(eventTrigger))) {
            const template = document.getElementById(`calendar-detail-${eventTrigger.dataset.calendarEvent}`);
            openPreview(template, eventTrigger);
            return true;
        }

        const dayTrigger = target.closest('[data-calendar-day]');
        if (dayTrigger && calendar.contains(dayTrigger)) {
            const template = document.getElementById(`calendar-day-${dayTrigger.dataset.calendarDay}`);
            openPreview(template, dayTrigger);
            return true;
        }

        return false;
    };

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => selectView(button.dataset.calendarViewButton));
    });

    filterToggle?.addEventListener('click', (event) => {
        event.stopPropagation();
        toggleFilterMenu();
    });

    phaseInputs.forEach((button) => {
        button.addEventListener('click', () => {
            selectedPhaseCategory = button.value || '';
            refreshMonthDays();
            closeFilterMenu();
        });
    });

    listStatus?.addEventListener('change', () => {
        selectedListStatus = listStatus.value || '';
        applyListFilters();
    });

    listSearch?.addEventListener('input', () => {
        selectedListQuery = listSearch.value.trim().toLowerCase();
        applyListFilters();
    });

    listSort?.addEventListener('change', () => {
        selectedListSort = listSort.value || 'date-soonest';
        applyListFilters();
    });

    calendar.addEventListener('click', (event) => activateCalendarControl(event.target));
    previewContent?.addEventListener('click', (event) => activateCalendarControl(event.target));
    document.querySelectorAll('[data-calendar-preview-close]').forEach((control) => {
        control.addEventListener('click', () => closePreview());
    });
    document.addEventListener('click', (event) => {
        if (filterPopover && !filterPopover.hidden && !phaseFilterControl?.contains(event.target)) {
            closeFilterMenu();
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (!preview.hidden) {
            closePreview();
            return;
        }
        closeFilterMenu();
    });

    refreshMonthDays();
    selectedListStatus = listStatus?.value || '';
    selectedListSort = listSort?.value || 'date-soonest';
    selectView(compactViewport.matches ? 'list' : 'month');
})();

(() => {
    const menus = document.querySelectorAll('[data-account-menu]');

    const setMenuOpen = (menu, open, restoreFocus = false) => {
        const toggle = menu.querySelector('[data-account-menu-toggle]');
        const dropdown = menu.querySelector('[data-account-menu-dropdown]');

        menu.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
        dropdown.setAttribute('aria-hidden', String(!open));

        if (restoreFocus) {
            toggle.focus();
        }
    };

    menus.forEach((menu) => {
        const toggle = menu.querySelector('[data-account-menu-toggle]');
        const dropdown = menu.querySelector('[data-account-menu-dropdown]');

        if (!toggle || !dropdown) {
            return;
        }

        toggle.addEventListener('click', () => {
            setMenuOpen(menu, !menu.classList.contains('is-open'));
        });

        dropdown.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => setMenuOpen(menu, false));
        });
    });

    document.addEventListener('pointerdown', (event) => {
        menus.forEach((menu) => {
            if (menu.classList.contains('is-open') && !menu.contains(event.target)) {
                setMenuOpen(menu, false);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }

        menus.forEach((menu) => {
            if (menu.classList.contains('is-open')) {
                setMenuOpen(menu, false, true);
            }
        });
    });
})();

(() => {
    const passwordToggle = document.querySelector('[data-toggle-password]');
    const passwordInput = document.querySelector('#password');

    if (!passwordToggle || !passwordInput) {
        return;
    }

    let isPasswordVisible = false;

    const updatePasswordVisibility = () => {
        if (isPasswordVisible) {
            passwordInput.type = 'text';
            passwordToggle.textContent = 'Hide';
            passwordToggle.setAttribute('aria-label', 'Hide password');
            passwordToggle.setAttribute('title', 'Hide password');
        } else {
            passwordInput.type = 'password';
            passwordToggle.textContent = 'Show';
            passwordToggle.setAttribute('aria-label', 'Show password');
            passwordToggle.setAttribute('title', 'Show password');
        }
    };

    passwordToggle.addEventListener('click', (event) => {
        event.preventDefault();
        isPasswordVisible = !isPasswordVisible;
        updatePasswordVisibility();
    });

    updatePasswordVisibility();
})();

(() => {
    const workflowSteps = document.querySelectorAll('.landing-workflow article');

    if (workflowSteps.length === 0) {
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (prefersReducedMotion) {
        workflowSteps.forEach((step) => {
            step.classList.add('is-revealed');
        });
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-revealed');
            }
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px',
    });

    workflowSteps.forEach((step) => {
        observer.observe(step);
    });
})();

(() => {
    const smoothScrollLinks = document.querySelectorAll('a[href^="#"]');

    if (smoothScrollLinks.length === 0) {
        return;
    }

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    smoothScrollLinks.forEach((link) => {
        link.addEventListener('click', (event) => {
            const href = link.getAttribute('href');
            if (href === '#') {
                return;
            }

            const target = document.querySelector(href);
            if (!target) {
                return;
            }

            event.preventDefault();

            if (prefersReducedMotion) {
                target.scrollIntoView();
                target.focus({ preventScroll: true });
            } else {
                target.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });
})();

(() => {
    const forms = document.querySelectorAll('[data-approval-decision-form]');

    forms.forEach((form) => {
        const decision = form.querySelector('[data-approval-decision]');
        const remarks = form.querySelector('[data-approval-remarks]');
        const remarksLabel = form.querySelector('[data-approval-remarks-label]');
        const remarksHelp = form.querySelector('[data-approval-remarks-help]');
        const panel = form.closest('[data-approval-decision-panel]');

        if (!decision || !remarks || !remarksLabel || !remarksHelp || !panel) {
            return;
        }

        const updateDecisionState = () => {
            const value = decision.value;
            const isReturn = value === 'RETURNED_FOR_REVISION';
            const isReject = value === 'REJECTED';
            const reasonRequired = isReturn || isReject;

            remarks.required = reasonRequired;
            remarksLabel.textContent = isReject
                ? 'Reason for rejection (required)'
                : (isReturn ? 'Reason for return (required)' : 'Remarks (optional)');
            remarksHelp.textContent = reasonRequired
                ? 'A reason is required for this decision.'
                : (value === 'APPROVED'
                    ? 'Remarks are optional when approving.'
                    : 'A reason is required when returning or rejecting a request.');
            panel.dataset.decisionTone = value === 'APPROVED'
                ? 'approve'
                : (isReturn ? 'return' : (isReject ? 'reject' : 'neutral'));
        };

        decision.addEventListener('change', updateDecisionState);
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                return;
            }

            const selectedLabel = decision.selectedOptions[0]?.textContent?.trim() || 'selected';
            const confirmed = window.confirm(`Submit the “${selectedLabel}” decision? This records the decision, request history, and your e-signature snapshot.`);
            if (!confirmed) {
                event.preventDefault();
            }
        });

        updateDecisionState();
    });
})();

(() => {
    document.querySelectorAll('form[data-confirm-message]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!form.checkValidity()) {
                return;
            }

            const message = form.dataset.confirmMessage;
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });
})();

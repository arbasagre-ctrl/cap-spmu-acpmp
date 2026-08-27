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
    const drawer = document.querySelector('[data-calendar-drawer]');
    const drawerBackdrop = document.querySelector('.calendar-drawer-backdrop');

    if (!calendar || !drawer || !drawerBackdrop) {
        return;
    }

    const drawerContent = drawer.querySelector('[data-calendar-drawer-content]');
    const drawerClose = drawer.querySelector('[data-calendar-drawer-close]');
    const viewButtons = calendar.querySelectorAll('[data-calendar-view-button]');
    const viewPanels = calendar.querySelectorAll('[data-calendar-view-panel]');
    const statusFilterBar = document.querySelector('[data-calendar-status-filters]');
    const statusFilterButtons = statusFilterBar
        ? Array.from(statusFilterBar.querySelectorAll('[data-calendar-status-filter]'))
        : [];
    const filterLive = statusFilterBar?.querySelector('[data-calendar-filter-live]');
    const filterEmpty = calendar.querySelector('[data-calendar-filter-empty]');
    const defaultEmptyStates = calendar.querySelectorAll('[data-calendar-default-empty]');
    const compactViewport = window.matchMedia('(max-width: 700px)');
    let lastTrigger = null;
    let closeTimer = null;
    let selectedStatus = '';

    const statusLabels = {
        active: 'Active',
        'due-soon': 'Due Soon',
        overdue: 'Overdue',
        returned: 'Returned',
    };

    const eventMatchesStatus = (eventTrigger) => {
        if (!selectedStatus) {
            return true;
        }

        const statuses = (eventTrigger.dataset.calendarFilterStatuses || '')
            .split(' ')
            .filter(Boolean);

        const ownOnly = calendar.dataset.calendarFilterOwnOnly === 'true';
        const inScope = !ownOnly || eventTrigger.dataset.calendarOwnRecord === 'true';

        return inScope && statuses.includes(selectedStatus);
    };

    const filterEventsIn = (context) => {
        context.querySelectorAll('[data-calendar-event]').forEach((eventTrigger) => {
            eventTrigger.hidden = !eventMatchesStatus(eventTrigger);
        });
    };

    const refreshMonthDays = () => {
        calendar.querySelectorAll('[data-calendar-day-events]').forEach((dayEvents) => {
            const occurrences = Array.from(
                dayEvents.querySelectorAll('[data-calendar-occurrence]')
            );
            const matching = occurrences.filter((occurrence) => {
                const eventTrigger = occurrence.querySelector('[data-calendar-event]');

                return eventTrigger && eventMatchesStatus(eventTrigger);
            });

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
    };

    const applyStatusFilter = () => {
        const listPanel = calendar.querySelector('[data-calendar-view-panel="list"]');
        const listEvents = listPanel
            ? Array.from(listPanel.children).filter((child) => child.matches('[data-calendar-event]'))
            : [];

        listEvents.forEach((eventTrigger) => {
            eventTrigger.hidden = !eventMatchesStatus(eventTrigger);
        });
        refreshMonthDays();

        const matchingCount = listEvents.filter(eventMatchesStatus).length;
        if (filterEmpty) {
            filterEmpty.hidden = !selectedStatus || matchingCount > 0;
        }
        defaultEmptyStates.forEach((emptyState) => {
            emptyState.hidden = Boolean(selectedStatus);
        });

        statusFilterButtons.forEach((button) => {
            const buttonStatus = button.dataset.calendarStatusFilter || '';
            const selected = buttonStatus === selectedStatus;
            button.classList.toggle('is-selected', selected);
            button.setAttribute('aria-pressed', String(selected));
        });

        if (filterLive) {
            filterLive.textContent = selectedStatus
                ? `Showing ${statusLabels[selectedStatus]} records.`
                : 'Showing all calendar records.';
        }
    };

    const selectView = (view) => {
        viewButtons.forEach((button) => {
            const selected = button.dataset.calendarViewButton === view;
            button.classList.toggle('active', selected);
            button.setAttribute('aria-pressed', String(selected));
        });
        viewPanels.forEach((panel) => {
            panel.hidden = panel.dataset.calendarViewPanel !== view;
        });
    };

    const clearStatusJumpHighlight = () => {
        calendar.querySelectorAll('.calendar-status-jump-target').forEach((element) => {
            element.classList.remove('calendar-status-jump-target');
        });
        calendar.querySelectorAll('.calendar-status-jump-day').forEach((element) => {
            element.classList.remove('calendar-status-jump-day');
        });
        filterEmpty?.classList.remove('calendar-status-jump-empty');
    };

    const nearestMatchingMonthOccurrence = () => {
        const candidates = Array.from(calendar.querySelectorAll('[data-calendar-occurrence]'))
            .filter((occurrence) => {
                const trigger = occurrence.querySelector('[data-calendar-event]');
                return trigger && eventMatchesStatus(trigger);
            });

        if (!candidates.length) {
            return null;
        }

        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();

        return candidates
            .map((occurrence) => {
                const rawDate = occurrence.dataset.calendarOccurrenceDate || '';
                const time = rawDate ? new Date(`${rawDate}T00:00:00`).getTime() : Number.POSITIVE_INFINITY;
                return { occurrence, distance: Math.abs(time - today), time };
            })
            .sort((left, right) => left.distance - right.distance || left.time - right.time)[0]?.occurrence || null;
    };

    const jumpToSelectedStatus = () => {
        clearStatusJumpHighlight();

        if (!selectedStatus) {
            const todayCell = calendar.querySelector('.calendar-day.is-today');
            const target = todayCell || calendar.querySelector('.calendar-toolbar');
            target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        const monthPanel = calendar.querySelector('[data-calendar-view-panel="month"]');
        const monthIsVisible = monthPanel && !monthPanel.hidden;

        if (monthIsVisible) {
            const occurrence = nearestMatchingMonthOccurrence();
            const eventTrigger = occurrence?.querySelector('[data-calendar-event]');
            if (occurrence && eventTrigger) {
                occurrence.hidden = false;
                eventTrigger.classList.add('calendar-status-jump-target');
                const day = occurrence.closest('.calendar-day');
                day?.classList.add('calendar-status-jump-day');
                day?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                window.setTimeout(clearStatusJumpHighlight, 2400);
                return;
            }
        } else {
            const listPanel = calendar.querySelector('[data-calendar-view-panel="list"]');
            const eventTrigger = listPanel
                ? Array.from(listPanel.querySelectorAll('[data-calendar-event]')).find((event) => !event.hidden && eventMatchesStatus(event))
                : null;
            if (eventTrigger) {
                eventTrigger.classList.add('calendar-status-jump-target');
                eventTrigger.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.setTimeout(clearStatusJumpHighlight, 2400);
                return;
            }
        }

        if (filterEmpty && !filterEmpty.hidden) {
            filterEmpty.classList.add('calendar-status-jump-empty');
            filterEmpty.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.setTimeout(clearStatusJumpHighlight, 2400);
        }
    };

    const openDrawer = (template, trigger) => {
        if (!template || !drawerContent) {
            return;
        }

        window.clearTimeout(closeTimer);
        drawerContent.replaceChildren(template.content.cloneNode(true));
        if (selectedStatus) {
            filterEventsIn(drawerContent);
        }
        if (!drawer.contains(trigger)) {
            lastTrigger = trigger;
        }
        drawer.hidden = false;
        drawerBackdrop.hidden = false;
        drawer.setAttribute('aria-hidden', 'false');
        document.body.classList.add('calendar-drawer-open');

        window.requestAnimationFrame(() => {
            drawer.classList.add('is-open');
            drawerBackdrop.classList.add('is-open');
            drawerClose?.focus();
        });
    };

    const closeDrawer = (restoreFocus = true) => {
        if (drawer.hidden) {
            return;
        }

        drawer.classList.remove('is-open');
        drawerBackdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('calendar-drawer-open');
        closeTimer = window.setTimeout(() => {
            drawer.hidden = true;
            drawerBackdrop.hidden = true;
            drawerContent?.replaceChildren();
        }, 190);

        if (restoreFocus && lastTrigger?.isConnected) {
            lastTrigger.focus();
        }
    };

    const activateCalendarControl = (target) => {
        const eventTrigger = target.closest('[data-calendar-event]');
        if (eventTrigger && (calendar.contains(eventTrigger) || drawer.contains(eventTrigger))) {
            const template = document.getElementById(`calendar-detail-${eventTrigger.dataset.calendarEvent}`);
            openDrawer(template, eventTrigger);
            return true;
        }

        const dayTrigger = target.closest('[data-calendar-day]');
        if (dayTrigger && calendar.contains(dayTrigger)) {
            const template = document.getElementById(`calendar-day-${dayTrigger.dataset.calendarDay}`);
            openDrawer(template, dayTrigger);
            return true;
        }

        return false;
    };

    viewButtons.forEach((button) => {
        button.addEventListener('click', () => selectView(button.dataset.calendarViewButton));
    });
    statusFilterButtons.forEach((button) => {
        const count = Number(button.dataset.calendarStatusCount || 0);
        button.dataset.calendarZero = String(count === 0 && Boolean(button.dataset.calendarStatusFilter));

        button.addEventListener('click', () => {
            const requestedStatus = button.dataset.calendarStatusFilter || '';
            selectedStatus = requestedStatus && selectedStatus === requestedStatus
                ? ''
                : requestedStatus;
            applyStatusFilter();
            window.requestAnimationFrame(jumpToSelectedStatus);
        });
    });

    calendar.addEventListener('click', (event) => activateCalendarControl(event.target));
    drawerContent?.addEventListener('click', (event) => activateCalendarControl(event.target));
    document.querySelectorAll('[data-calendar-drawer-close]').forEach((control) => {
        control.addEventListener('click', () => closeDrawer());
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.hidden) {
            closeDrawer();
        }
    });

    if (statusFilterBar) {
        applyStatusFilter();
    }
    selectView(compactViewport.matches ? 'list' : 'month');
})();

(() => {
    const browser = document.querySelector('[data-borrower-accountability]');
    const cardFilters = document.querySelector('[data-accountability-card-filters]');

    if (!browser || !cardFilters) {
        return;
    }

    const recordsContainer = browser.querySelector('[data-accountability-records]');
    const records = Array.from(browser.querySelectorAll('[data-accountability-record]'));
    const cards = Array.from(cardFilters.querySelectorAll('[data-accountability-card-filter]'));
    const search = browser.querySelector('[data-accountability-search]');
    const status = browser.querySelector('[data-accountability-status]');
    const sort = browser.querySelector('[data-accountability-sort]');
    const clear = browser.querySelector('[data-accountability-clear]');
    const resultCount = browser.querySelector('[data-accountability-result-count]');
    const empty = browser.querySelector('[data-accountability-empty]');
    let selectedCategory = '';

    const applyFilters = () => {
        const query = (search?.value || '').trim().toLowerCase();
        const selectedStatus = status?.value || '';
        const sortDirection = sort?.value === 'oldest' ? 'oldest' : 'newest';
        const orderedRecords = [...records].sort((left, right) => {
            const leftDate = Number(left.dataset.date || 0);
            const rightDate = Number(right.dataset.date || 0);

            return sortDirection === 'oldest'
                ? leftDate - rightDate
                : rightDate - leftDate;
        });
        let visibleCount = 0;

        orderedRecords.forEach((record) => {
            recordsContainer?.append(record);

            const matchesCategory = !selectedCategory
                || record.dataset.category === selectedCategory;
            const matchesSearch = !query
                || (record.dataset.search || '').includes(query);
            const matchesStatus = !selectedStatus
                || record.dataset.status === selectedStatus;
            const visible = matchesCategory && matchesSearch && matchesStatus;

            record.hidden = !visible;
            if (visible) {
                visibleCount += 1;
            }
        });

        cards.forEach((card) => {
            const selected = card.dataset.accountabilityCardFilter === selectedCategory;
            card.classList.toggle('is-selected', selected);
            card.setAttribute('aria-pressed', String(selected));
        });

        if (resultCount) {
            resultCount.textContent = records.length === visibleCount
                ? `Showing ${visibleCount} ${visibleCount === 1 ? 'record' : 'records'}`
                : `Showing ${visibleCount} of ${records.length} records`;
        }

        if (empty) {
            empty.hidden = visibleCount > 0;
            const heading = empty.querySelector('strong');
            const copy = empty.querySelector('span');
            const hasRecords = records.length > 0;

            if (heading) {
                heading.textContent = hasRecords
                    ? 'No records match the current filters.'
                    : 'You have no unresolved obligations.';
            }
            if (copy) {
                copy.textContent = hasRecords
                    ? 'Adjust the search or filters to see other records.'
                    : 'There are no open overdue, property, billing, or restriction records on your account.';
            }
        }
    };

    cards.forEach((card) => {
        card.addEventListener('click', () => {
            const requestedCategory = card.dataset.accountabilityCardFilter || '';

            if (selectedCategory === requestedCategory) {
                selectedCategory = '';
                if (search) {
                    search.value = '';
                }
                if (status) {
                    status.value = '';
                }
            } else {
                selectedCategory = requestedCategory;
            }

            applyFilters();
        });
    });

    search?.addEventListener('input', applyFilters);
    status?.addEventListener('change', applyFilters);
    sort?.addEventListener('change', applyFilters);
    clear?.addEventListener('click', () => {
        selectedCategory = '';
        if (search) {
            search.value = '';
        }
        if (status) {
            status.value = '';
        }
        if (sort) {
            sort.value = 'newest';
        }
        applyFilters();
    });

    applyFilters();
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

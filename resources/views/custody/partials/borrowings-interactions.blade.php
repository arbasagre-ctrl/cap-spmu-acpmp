<script>
(() => {
    const initializeMyBorrowings = () => {
        const browser = document.querySelector('[data-my-borrowings]');

        if (!browser) {
            return;
        }

        const tabs = Array.from(browser.querySelectorAll('[data-borrowings-tab]'));
        const panels = Array.from(browser.querySelectorAll('[data-borrowings-panel]'));

        if (tabs.length === 0 || panels.length === 0) {
            return;
        }

        const showTab = (name) => {
            tabs.forEach((tab) => {
                const selected = tab.dataset.borrowingsTab === name;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                tab.tabIndex = selected ? 0 : -1;
            });

            panels.forEach((panel) => {
                panel.hidden = panel.dataset.borrowingsPanel !== name;
            });
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => showTab(tab.dataset.borrowingsTab));

            tab.addEventListener('keydown', (event) => {
                if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                    return;
                }

                event.preventDefault();

                const step = event.key === 'ArrowRight' ? 1 : -1;
                const next = tabs[(index + step + tabs.length) % tabs.length];

                showTab(next.dataset.borrowingsTab);
                next.focus();
            });
        });

        showTab(tabs[0].dataset.borrowingsTab);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeMyBorrowings, { once: true });
    } else {
        initializeMyBorrowings();
    }
})();
</script>

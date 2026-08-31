<script>
(() => {
    const workspace = document.querySelector('[data-release-process]');
    if (!workspace || workspace.dataset.releaseProcessInitialized === '1') return;
    workspace.dataset.releaseProcessInitialized = '1';

    const controls = [...workspace.querySelectorAll('[data-release-panel-toggle], [data-release-schedule-edit]')];
    const setPanel = (id, open, focus = false) => {
        const panel = document.getElementById(id);
        if (!panel || !workspace.contains(panel)) return;
        panel.hidden = !open;
        controls.forEach((control) => {
            if (control.getAttribute('aria-controls') === id) control.setAttribute('aria-expanded', String(open));
        });
        if (open && focus) panel.querySelector('input, button, textarea')?.focus();
    };

    controls.forEach((control) => {
        control.addEventListener('click', () => {
            const id = control.getAttribute('aria-controls');
            const edit = control.hasAttribute('data-release-schedule-edit');
            setPanel(id, edit || control.getAttribute('aria-expanded') !== 'true', edit);
        });
    });

    workspace.querySelector('[data-release-schedule-cancel]')?.addEventListener('click', () => {
        workspace.querySelector('#release-schedule-editor form')?.reset();
        setPanel('release-schedule-editor', false);
        workspace.querySelector('[data-release-schedule-edit]')?.focus();
    });
})();
</script>

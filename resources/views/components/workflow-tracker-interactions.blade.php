@once
<style>
    .workflow-tracker__interactive {
        position: relative;
        min-width: 0;
        border-radius: 8px;
        outline: none;
        cursor: help;
    }

    .workflow-tracker__interactive:focus-visible {
        outline: 2px solid #1769e0;
        outline-offset: 4px;
    }

    .workflow-tracker__meta {
        display: block;
        margin-top: 4px;
        color: var(--text-muted, #667085);
        font-size: 11px;
        line-height: 1.35;
    }

    .workflow-tracker-floating-tooltip {
        position: fixed;
        z-index: 99999;
        width: max-content;
        max-width: min(260px, calc(100vw - 24px));
        padding: 10px 12px;
        border: 1px solid var(--border, #d8e1ec);
        border-radius: 10px;
        background: var(--surface-elevated, #ffffff);
        color: var(--text, #172b4d);
        box-shadow: 0 12px 30px rgba(20, 42, 74, .16);
        text-align: left;
        pointer-events: none;
        opacity: 0;
        visibility: hidden;
        transform: translateY(4px);
        transition:
            opacity .14s ease,
            transform .14s ease,
            visibility .14s ease;
    }

    .workflow-tracker-floating-tooltip.is-visible {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .workflow-tracker-floating-tooltip strong {
        display: block;
        margin: 0 0 3px;
        font-size: 13px;
        line-height: 1.3;
    }

    .workflow-tracker-floating-tooltip .workflow-tooltip-meta {
        display: block;
        margin-bottom: 7px;
        color: var(--text-muted, #667085);
        font-size: 11px;
        line-height: 1.35;
    }

    .workflow-tracker-floating-tooltip p {
        margin: 0;
        color: var(--text, #344054);
        font-size: 12px;
        line-height: 1.5;
    }

    html[data-theme="dark"] .workflow-tracker-floating-tooltip {
        border-color: var(--border, #334155);
        background: var(--surface-elevated, #18212f);
        color: var(--text, #f1f5f9);
        box-shadow: 0 14px 34px rgba(0, 0, 0, .34);
    }

    html[data-theme="dark"] .workflow-tracker-floating-tooltip p {
        color: var(--text, #e2e8f0);
    }

    /* REQUEST TRACKER FINAL RESPONSIVE POLISH */

    .request-tracker__scroll {
        width: 100% !important;
        overflow-x: visible !important;
        overflow-y: visible !important;
        scrollbar-width: none !important;
    }

    .request-tracker__scroll::-webkit-scrollbar {
        display: none !important;
    }

    .request-tracker__rail {
        width: 100% !important;
        min-width: 0 !important;
        grid-template-columns: repeat(8, minmax(0, 1fr)) !important;
        column-gap: 8px !important;
    }

    .request-tracker__step {
        min-width: 0 !important;
    }

    .request-tracker__copy {
        min-width: 0 !important;
        width: 100%;
        text-align: center;
    }

    .request-tracker__copy strong {
        display: block;
        max-width: 100%;
        white-space: normal !important;
        overflow-wrap: anywhere;
        font-size: 12px;
        line-height: 1.25;
    }

    .request-tracker__copy time,
    .request-tracker__pending-label {
        display: block;
        margin-top: 4px;
        white-space: normal !important;
        font-size: 10px;
        line-height: 1.25;
    }

    /*
     * Tablet: wrap into two clean rows instead of horizontal scrolling.
     */
    @media (max-width: 900px) {
        .request-tracker__rail {
            grid-template-columns: repeat(4, minmax(0, 1fr)) !important;
            row-gap: 28px !important;
        }

        .request-tracker__step::before,
        .request-tracker__step::after {
            max-width: 100% !important;
        }
    }

    /*
     * Mobile: two milestones per row.
     */
    @media (max-width: 620px) {
        .request-tracker__rail {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            column-gap: 14px !important;
            row-gap: 26px !important;
        }

        .workflow-tracker-floating-tooltip {
            max-width: min(240px, calc(100vw - 24px));
        }
    }

    /* FINAL FLAT 2D COMPLETED STATE */
    :root {
        --tracker-complete-green: #00B300;
    }

    /* Completed markers */
    .request-tracker__step.is-complete .request-tracker__marker,
    .laundry-progress-step.is-complete .laundry-progress-marker {
        background: #00B300 !important;
        background-image: none !important;
        border: none !important;
        outline: none !important;
        box-shadow: none !important;
        filter: none !important;
        color: #ffffff !important;
    }

    /* Kill any decorative ring around completed marker */
    .request-tracker__step.is-complete .request-tracker__marker::before,
    .request-tracker__step.is-complete .request-tracker__marker::after,
    .laundry-progress-step.is-complete .laundry-progress-marker::before,
    .laundry-progress-step.is-complete .laundry-progress-marker::after {
        content: none !important;
        display: none !important;
        border: 0 !important;
        outline: 0 !important;
        box-shadow: none !important;
    }

    /* Plain white check */
    .request-tracker__step.is-complete .request-tracker__marker svg {
        color: #ffffff !important;
        stroke: #ffffff !important;
        fill: none !important;
        filter: none !important;
    }

    /* Completed connector line */
    .request-tracker__step.is-complete::before,
    .request-tracker__step.is-complete::after,
    .laundry-progress-step.is-complete::before,
    .laundry-progress-step.is-complete::after {
        background: #00B300 !important;
        background-image: none !important;
        border: 0 !important;
        outline: 0 !important;
        box-shadow: none !important;
    }
</style>

<script>
(() => {
    if (window.__spmuWorkflowTrackerTooltipReady) {
        return;
    }

    window.__spmuWorkflowTrackerTooltipReady = true;

    const tooltip = document.createElement('div');
    tooltip.className = 'workflow-tracker-floating-tooltip';
    tooltip.setAttribute('role', 'tooltip');
    tooltip.setAttribute('aria-hidden', 'true');

    const title = document.createElement('strong');
    const meta = document.createElement('span');
    meta.className = 'workflow-tooltip-meta';
    const description = document.createElement('p');

    tooltip.append(title, meta, description);
    document.body.appendChild(tooltip);

    let activeAnchor = null;

    const positionTooltip = (anchor) => {
        const rect = anchor.getBoundingClientRect();

        tooltip.style.left = '0px';
        tooltip.style.top = '0px';
        tooltip.style.visibility = 'hidden';
        tooltip.style.opacity = '0';
        tooltip.classList.add('is-visible');

        const tooltipWidth = tooltip.offsetWidth;
        const tooltipHeight = tooltip.offsetHeight;

        let left = rect.left + (rect.width / 2) - (tooltipWidth / 2);
        left = Math.max(
            12,
            Math.min(left, window.innerWidth - tooltipWidth - 12)
        );

        let top = rect.top - tooltipHeight - 10;

        if (top < 12) {
            top = rect.bottom + 10;
        }

        tooltip.style.left = `${left}px`;
        tooltip.style.top = `${top}px`;
        tooltip.style.visibility = '';
        tooltip.style.opacity = '';
    };

    const showTooltip = (anchor) => {
        if (!anchor) return;

        activeAnchor = anchor;

        title.textContent =
            anchor.dataset.workflowTitle || '';

        meta.textContent =
            anchor.dataset.workflowMeta || '';

        description.textContent =
            anchor.dataset.workflowDescription || '';

        tooltip.setAttribute('aria-hidden', 'false');
        tooltip.classList.add('is-visible');

        positionTooltip(anchor);
    };

    const hideTooltip = () => {
        activeAnchor = null;
        tooltip.classList.remove('is-visible');
        tooltip.setAttribute('aria-hidden', 'true');
    };

    const initializeSteps = () => {
        document
            .querySelectorAll('[data-workflow-step]')
            .forEach((step) => {
                if (step.dataset.workflowTooltipBound === '1') {
                    return;
                }

                step.dataset.workflowTooltipBound = '1';

                step.addEventListener('mouseenter', () => {
                    showTooltip(step);
                });

                step.addEventListener('mouseleave', () => {
                    if (document.activeElement !== step) {
                        hideTooltip();
                    }
                });

                step.addEventListener('focus', () => {
                    showTooltip(step);
                });

                step.addEventListener('blur', () => {
                    hideTooltip();
                });

                step.addEventListener('click', (event) => {
                    event.stopPropagation();

                    if (
                        activeAnchor === step &&
                        tooltip.classList.contains('is-visible')
                    ) {
                        hideTooltip();
                    } else {
                        showTooltip(step);
                    }
                });

                step.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        hideTooltip();
                        step.blur();
                        return;
                    }

                    if (
                        event.key === 'Enter' ||
                        event.key === ' '
                    ) {
                        event.preventDefault();

                        if (
                            activeAnchor === step &&
                            tooltip.classList.contains('is-visible')
                        ) {
                            hideTooltip();
                        } else {
                            showTooltip(step);
                        }
                    }
                });
            });
    };

    initializeSteps();

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-workflow-step]')) {
            hideTooltip();
        }
    });

    window.addEventListener('resize', hideTooltip);
    window.addEventListener('scroll', hideTooltip, true);
})();
</script>
@endonce
{{--
    One tooltip for every Analytics chart.

    Charts here are plain Blade, SVG and CSS, and each mark already carries the
    exact figure it draws. So a mark only has to declare what it is worth -
    data-tip-title plus data-tip-rows - and this file owns everything else:
    the surface, where it goes, and how it is reached by mouse, touch and
    keyboard. Nothing below reads a record or recomputes a figure.

    WHY THE ELEMENT LIVES ON <body>
    ------------------------------
    Several cards clip their content to keep rounded corners, and a tooltip
    anchored inside one is cut off by that clip. The single tooltip node is
    appended to the document instead and positioned in viewport coordinates,
    so no card can crop it and no z-index inside a card can bury it.

    ROWS
    ----
    data-tip-rows is a JSON array. An entry is either a plain string, drawn as
    one line, or a [label, value] pair, drawn as a label/value row. Blade
    escapes the JSON into the attribute and the DOM hands it back intact.
--}}

<style>
/*
| The surface. Deliberately quiet: a chart tooltip is read at a glance and
| next to the mark it describes, so it borrows the card's own vocabulary
| rather than introducing a second one.
*/
.analytics-tip {
    position: fixed;
    z-index: 900;
    max-width: min(260px, calc(100vw - 24px));
    padding: 9px 11px;
    border: 1px solid var(--border);
    border-radius: 9px;
    background: var(--surface-elevated);
    box-shadow: 0 6px 20px rgba(16, 34, 62, .14);
    color: var(--heading);
    font-size: 11.5px;
    line-height: 1.45;
    pointer-events: none;
    opacity: 0;
    transform: translateY(2px);
    transition: opacity var(--motion-fast, .12s) ease, transform var(--motion-fast, .12s) ease;
}

.analytics-tip.is-open { opacity: 1; transform: translateY(0); }

.analytics-tip-title {
    margin: 0;
    color: var(--heading);
    font-size: 11.5px;
    font-weight: 750;
    line-height: 1.3;
    overflow-wrap: anywhere;
}

.analytics-tip-rows { display: grid; gap: 2px; margin: 4px 0 0; padding: 0; list-style: none; }

/* A label/value pair keeps its value hard right so figures line up. */
.analytics-tip-rows li {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 14px;
    color: var(--text-muted);
    font-size: 11px;
}

.analytics-tip-rows li.is-plain { display: block; color: var(--heading); font-weight: 650; }

.analytics-tip-rows li > span:last-child {
    color: var(--heading);
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.analytics-tip-rows li.is-plain > span:last-child { font-weight: 650; white-space: normal; }

/* A mark that is currently describing itself, by hover, focus or tap. */
[data-chart-tip].is-tip-active { filter: brightness(1.06) saturate(1.05); }
[data-chart-tip]:focus-visible { outline: 2px solid var(--interactive); outline-offset: 2px; }

/*
| A stacked-bar segment is only a few pixels tall, so its hit area is widened
| with a transparent pseudo-element rather than by drawing the bar thicker -
| the reading stays honest while the target does not.
|
| Every other mark carries its tooltip on the whole row or marker it belongs
| to, which is already large enough to hit.
*/
.analytics-dist-seg[data-chart-tip] { position: relative; cursor: default; }

.analytics-dist-seg[data-chart-tip]::after {
    content: "";
    position: absolute;
    inset: -6px 0;
}

.analytics-donut-arc[data-chart-tip] { cursor: default; }

@media (prefers-reduced-motion: reduce) {
    .analytics-tip { transition: none; }
}
</style>

<script>
(() => {
    const MARK = '[data-chart-tip]';
    const GAP = 10;
    const EDGE = 8;

    let tip = null;
    let anchor = null;
    /* A tooltip opened by tap or keyboard stays until it is dismissed. */
    let pinned = false;
    /* click carries no pointerType, so the last pointer down is remembered. */
    let lastPointer = 'mouse';

    const build = () => {
        if (tip) return tip;

        tip = document.createElement('div');
        tip.className = 'analytics-tip';
        tip.setAttribute('role', 'tooltip');
        tip.hidden = true;
        document.body.appendChild(tip);

        return tip;
    };

    /* Rows are whatever the mark declared; this never derives a figure. */
    const rowsOf = (el) => {
        const raw = el.getAttribute('data-tip-rows');
        if (!raw) return [];

        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    };

    const render = (el) => {
        const node = build();
        const title = el.getAttribute('data-tip-title') || '';
        const rows = rowsOf(el);

        node.textContent = '';

        if (title) {
            const heading = document.createElement('p');
            heading.className = 'analytics-tip-title';
            heading.textContent = title;
            node.appendChild(heading);
        }

        if (rows.length === 0) return;

        const list = document.createElement('ul');
        list.className = 'analytics-tip-rows';

        rows.forEach((row) => {
            const item = document.createElement('li');

            if (Array.isArray(row)) {
                const label = document.createElement('span');
                label.textContent = row[0];
                const value = document.createElement('span');
                value.textContent = row[1];
                item.append(label, value);
            } else {
                item.className = 'is-plain';
                const value = document.createElement('span');
                value.textContent = row;
                item.appendChild(value);
            }

            list.appendChild(item);
        });

        node.appendChild(list);
    };

    /*
     * Above the mark and centred on it, unless that would leave the viewport.
     * Measured after render, because the height decides whether it fits above.
     */
    const place = () => {
        if (!tip || !anchor || !anchor.isConnected) return;

        const rect = anchor.getBoundingClientRect();
        const box = tip.getBoundingClientRect();

        let top = rect.top - box.height - GAP;
        if (top < EDGE) top = rect.bottom + GAP;
        if (top + box.height > window.innerHeight - EDGE) {
            top = Math.max(EDGE, window.innerHeight - box.height - EDGE);
        }

        let left = rect.left + (rect.width / 2) - (box.width / 2);
        left = Math.min(left, window.innerWidth - box.width - EDGE);
        left = Math.max(EDGE, left);

        tip.style.top = Math.round(top) + 'px';
        tip.style.left = Math.round(left) + 'px';
    };

    const hide = () => {
        if (anchor) anchor.classList.remove('is-tip-active');
        if (tip) {
            tip.classList.remove('is-open');
            tip.hidden = true;
        }
        anchor = null;
        pinned = false;
    };

    const show = (el, stick) => {
        if (anchor && anchor !== el) anchor.classList.remove('is-tip-active');

        anchor = el;
        pinned = Boolean(stick);
        render(el);
        el.classList.add('is-tip-active');

        tip.hidden = false;
        /* Positioned only once it has a size to position. */
        place();
        tip.classList.add('is-open');
    };

    /* ---------------------------------------------------------------- mouse */

    document.addEventListener('pointerdown', (event) => {
        lastPointer = event.pointerType || 'mouse';
    }, true);

    document.addEventListener('pointerover', (event) => {
        if (event.pointerType !== 'mouse') return;

        const el = event.target.closest(MARK);
        if (!el || el === anchor) return;
        if (pinned) return;

        show(el, false);
    });

    document.addEventListener('pointerout', (event) => {
        if (event.pointerType !== 'mouse' || pinned) return;

        const el = event.target.closest(MARK);
        if (!el || el !== anchor) return;
        if (event.relatedTarget && el.contains(event.relatedTarget)) return;

        hide();
    });

    /* ----------------------------------------------------------- touch, tap */

    /*
     * Capture, so a tap on a mark never reaches the whole-card drill-down
     * listener bound further out. A mark that is itself a link keeps its
     * destination: the first tap reveals the figure, a second follows it,
     * which is the only way both can exist on a device without hover.
     */
    document.addEventListener('click', (event) => {
        const el = event.target.closest(MARK);

        if (!el) {
            if (pinned) hide();
            return;
        }

        const link = el.closest('a[href]');
        const wasPinnedHere = pinned && anchor === el;
        const touched = lastPointer !== 'mouse';

        event.stopPropagation();

        if (link && (wasPinnedHere || !touched)) {
            /* Let the anchor do its job. */
            return;
        }

        if (link) event.preventDefault();

        if (wasPinnedHere) {
            hide();
            return;
        }

        show(el, true);
    }, true);


    /* ------------------------------------------------------------- keyboard */

    document.addEventListener('focusin', (event) => {
        const el = event.target.closest(MARK);
        if (!el) return;

        show(el, true);
    });

    document.addEventListener('focusout', (event) => {
        const el = event.target.closest(MARK);
        if (el && el === anchor) hide();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && anchor) hide();
    });

    /* --------------------------------------------------------- keep in view */

    window.addEventListener('scroll', () => {
        if (!anchor) return;
        pinned ? place() : hide();
    }, true);

    window.addEventListener('resize', () => {
        if (anchor) place();
    });
})();
</script>

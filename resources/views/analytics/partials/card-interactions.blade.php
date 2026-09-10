{{--
    Whole-card drill-down.

    A card is not wrapped in an anchor. Several of them already contain their
    own links - "View all", ranked rows, chart markers - and nesting anchors
    produces invalid markup that browsers silently unnest, which breaks the
    inner control. Instead the card carries its destination as data, a real
    focusable link in the header carries the semantics and the keyboard path,
    and this script extends the hit area to the rest of the card surface.

    A click that starts on an inner control is left to that control, so the
    card action never fires twice or hijacks a more specific destination.
--}}
<script>
(() => {
    const CARD = '[data-card-detail]';

    /* Anything that already does something on click owns its own click. */
    const INNER = 'a, button, select, input, textarea, label, summary, details, [role="button"], [role="link"], [data-chart-tip]';

    const open = (card) => {
        const href = card.getAttribute('data-card-detail');

        if (href) {
            window.location.assign(href);
        }
    };

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const card = event.target.closest(CARD);
        if (!card) return;

        /* An inner control was the real target. */
        if (event.target.closest(INNER)) return;

        /* Selecting text inside a card is reading, not navigating. */
        const selection = window.getSelection();
        if (selection && selection.type === 'Range' && String(selection).trim() !== '') return;

        open(card);
    });

    /*
     * Keyboard activation belongs to the header link, which is a real anchor
     * and already handles Enter. This only adds Space for the card itself when
     * a host has given it button semantics.
     */
    document.addEventListener('keydown', (event) => {
        if (event.key !== ' ' && event.key !== 'Spacebar') return;

        const card = event.target.closest(CARD);
        if (!card || card !== event.target) return;
        if (card.getAttribute('role') !== 'button') return;

        event.preventDefault();
        open(card);
    });
})();
</script>

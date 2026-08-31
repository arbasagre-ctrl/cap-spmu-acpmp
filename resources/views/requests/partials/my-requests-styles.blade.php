<style>
    /*
    |--------------------------------------------------------------------------
    | Borrower workspace - My Requests
    |--------------------------------------------------------------------------
    */
    .my-requests { --mr-accent: #1769aa; display: block; container-type: inline-size; container-name: my-requests; }

    html[data-theme="dark"] .my-requests { --mr-accent: #72b7f4; }

    /* Search and status controls */
    .mr-toolbar {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(240px, 400px);
        gap: 16px;
        margin-bottom: 14px;
        padding: 19px 21px;
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
    }

    .mr-field {
        display: grid;
        gap: 6px;
        margin: 0;
        min-width: 0;
        color: var(--text-secondary);
        font-size: 11.5px;
        font-weight: 700;
    }

    .mr-field-shell {
        position: relative;
        display: block;
        background: var(--input-bg);
        border: 1px solid var(--border);
        border-radius: 8px;
    }

    .mr-field-shell:focus-within {
        border-color: var(--interactive);
        box-shadow: var(--focus-ring);
    }

    .mr-field-shell input {
        width: 100%;
        min-height: 44px;
        min-width: 0;
        padding: 0 12px 0 40px;
        background: transparent;
        border: 0;
        box-shadow: none;
        outline: 0;
        font-size: 12.5px;
    }

    .mr-field-shell .search-input-icon {
        position: absolute;
        top: 50%;
        left: 13px;
        display: inline-flex;
        color: var(--text-soft);
        transform: translateY(-50%);
        pointer-events: none;
    }

    .mr-field-status select {
        width: 100%;
        min-height: 44px;
        margin: 0;
        font-size: 12.5px;
    }

    /* Results panel */
    #my-requests-results {
        --mr-accent: #0069e8;
        --mr-line: color-mix(in srgb, var(--border) 65%, var(--surface-elevated));
        min-width: 0;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 10px;
        background: var(--surface-elevated);
    }
    #my-requests-results [hidden] { display: none !important; }
    #my-requests-results .mr-listing-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 5px 12px 18px; }
    #my-requests-results .mr-result-count { margin: 0; color: var(--heading); font-size: clamp(15px, 1.5cqw, 18px); font-weight: 750; }
    #my-requests-results .mr-sort { display: inline-flex; align-items: center; gap: 10px; min-height: 42px; margin: 0; padding: 0 12px; border: 1px solid var(--border); border-radius: 8px; background: var(--surface-elevated); color: var(--text-muted); font-size: 14px; font-weight: 400; white-space: nowrap; }
    #my-requests-results .mr-sort > .ui-icon { flex-shrink: 0; color: var(--mr-accent); }
    #my-requests-results .mr-sort > .ui-icon:first-child { transform: scaleX(-1); }
    #my-requests-results .mr-sort select { appearance: none; width: auto; min-width: 118px; min-height: 40px; margin: 0; padding: 0 2px; border: 0; border-radius: 0; color: var(--mr-accent); background: transparent; background-image: none; box-shadow: none; font-size: 14px; font-weight: 700; cursor: pointer; }
    #my-requests-results .mr-sort select:focus { outline: 0; box-shadow: none; }
    #my-requests-results .mr-sort:focus-within { border-color: var(--mr-accent); box-shadow: var(--focus-ring); }
    #my-requests-results .mr-sort-chevron { pointer-events: none; }

    /* One horizontal record: identity, dates, quantities, status and actions. */
    #my-requests-results .mr-list { display: grid; gap: 18px; }
    #my-requests-results .mr-row {
        display: grid;
        grid-template-columns: clamp(52px, 5.2cqw, 64px) minmax(0, 1.45fr) minmax(0, 3.12fr) minmax(155px, 1.07fr) 24px;
        grid-template-areas: "tile identity facts actions menu";
        align-items: center;
        gap: 14px;
        min-height: 136px;
        padding: 22px 16px;
        border: 1px solid var(--mr-line);
        border-radius: 11px;
        background: var(--surface-elevated);
        box-shadow: 0 4px 10px rgba(16, 42, 67, .07);
    }
    #my-requests-results .mr-row:hover { border-color: var(--border-strong); }
    #my-requests-results .mr-row.is-action-required { border-left: 3px solid var(--warning-border); padding-left: 14px; }
    #my-requests-results .mr-row-tile { grid-area: tile; display: grid; place-items: center; width: 100%; aspect-ratio: 1; border-radius: 14px; }
    #my-requests-results .mr-row-tile > .ui-icon { width: 32px; height: 32px; }
    #my-requests-results .mr-row-tile.is-blue { color: var(--mr-accent); background: #ebf3ff; }
    #my-requests-results .mr-row-tile.is-green { color: var(--success); background: var(--success-bg); }
    #my-requests-results .mr-row-tile.is-violet { color: #7352ce; background: #f0ebff; }
    #my-requests-results .mr-row-tile.is-amber { color: var(--warning); background: var(--warning-bg); }
    #my-requests-results .mr-row-tile.is-red { color: var(--danger); background: var(--danger-bg); }
    #my-requests-results .mr-row-tile.is-neutral { color: var(--neutral); background: var(--neutral-bg); }

    #my-requests-results .mr-row-identity { grid-area: identity; display: grid; gap: 8px; min-width: 0; }
    #my-requests-results .mr-row-reference { color: var(--heading); font-size: clamp(14px, 1.45cqw, 18px); font-weight: 800; line-height: 1.4; text-decoration: none; overflow-wrap: anywhere; }
    #my-requests-results .mr-row-reference:hover { color: var(--mr-accent); text-decoration: underline; }
    #my-requests-results .mr-row-purpose { margin: 0; color: var(--heading); font-size: clamp(13px, 1.3cqw, 16px); font-weight: 700; line-height: 1.45; overflow-wrap: anywhere; }
    #my-requests-results .mr-row-context { margin: 0; color: var(--text-muted); font-size: clamp(12px, 1.16cqw, 14px); line-height: 1.5; overflow-wrap: anywhere; }
    #my-requests-results .mr-row-meta { grid-area: facts; display: grid; grid-template-columns: minmax(0, 1.28fr) minmax(0, .9fr) minmax(0, 1.22fr); align-items: center; min-width: 0; }
    #my-requests-results .mr-row-fact { display: grid; align-content: center; gap: 14px; min-width: 0; min-height: 62px; padding: 0 14px; border-left: 1px solid var(--mr-line); }
    #my-requests-results .mr-row-fact-label { color: var(--text-secondary); font-size: clamp(12px, 1.15cqw, 14px); font-weight: 500; line-height: 1.4; }
    #my-requests-results .mr-row-fact-value { display: flex; align-items: center; gap: 5px; min-width: 0; color: var(--text); font-size: clamp(11px, 1.1cqw, 13px); font-weight: 400; line-height: 1.55; }
    #my-requests-results .mr-row-fact-value .ui-icon { flex: 0 0 16px; width: 16px; height: 16px; color: var(--mr-accent); }
    #my-requests-results .mr-row-dot { color: var(--text-secondary); }

    #my-requests-results .mr-row-action { grid-area: actions; display: grid; justify-items: start; align-content: center; gap: 14px; min-width: 0; }
    #my-requests-results .mr-badge { display: inline-flex; align-items: center; gap: 7px; min-height: 33px; max-width: 100%; padding: 6px 11px; border: 0; border-radius: 999px; font-size: clamp(11px, 1.04cqw, 13px); font-weight: 650; line-height: 1.4; white-space: nowrap; }
    #my-requests-results .mr-badge::before { content: ""; flex: 0 0 7px; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
    #my-requests-results .mr-badge.is-blue { color: var(--mr-accent); background: #eaf3ff; }
    #my-requests-results .mr-badge.is-green { color: var(--success); background: var(--success-bg); }
    #my-requests-results .mr-badge.is-amber { color: var(--warning); background: var(--warning-bg); }
    #my-requests-results .mr-badge.is-red { color: var(--danger); background: var(--danger-bg); }
    #my-requests-results .mr-badge.is-neutral { color: var(--neutral); background: var(--neutral-bg); }
    #my-requests-results .mr-row-view { display: inline-flex; align-items: center; gap: 10px; min-height: 28px; padding: 0 2px; border: 0; border-radius: 4px; color: var(--mr-accent); background: transparent; font-size: clamp(13px, 1.26cqw, 16px); font-weight: 750; text-decoration: none; white-space: nowrap; }
    #my-requests-results .mr-row-view .ui-icon { flex-shrink: 0; }
    #my-requests-results .mr-row-view:hover { color: var(--mr-accent); text-decoration: underline; }

    /* Accessible overflow menu; retain all existing record actions. */
    #my-requests-results .mr-row-menu { grid-area: menu; position: relative; justify-self: center; }
    #my-requests-results .mr-row-menu-trigger { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 36px; min-height: 36px; padding: 0; border: 1px solid transparent; border-radius: 6px; color: var(--text-muted); background: transparent; box-shadow: none; cursor: pointer; transform: none; }
    #my-requests-results .mr-row-menu-trigger > .ui-icon { width: 22px; height: 22px; }
    #my-requests-results .mr-row-menu-trigger:hover,
    #my-requests-results .mr-row-menu-trigger[aria-expanded="true"] { color: #fff; background: var(--mr-accent); border-color: var(--mr-accent); }
    #my-requests-results .mr-row-menu-panel { position: absolute; top: calc(100% + 6px); right: 0; z-index: 30; display: grid; min-width: 190px; padding: 5px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); box-shadow: var(--shadow); }
    #my-requests-results .mr-row-menu-panel > a,
    #my-requests-results .mr-row-menu-panel > button { display: block; width: 100%; min-height: 34px; padding: 8px 10px; border: 0; border-radius: 5px; background: transparent; color: var(--text-secondary); font-size: 12px; font-weight: 600; line-height: 1.4; text-align: left; text-decoration: none; cursor: pointer; }
    #my-requests-results .mr-row-menu-panel > a:hover,
    #my-requests-results .mr-row-menu-panel > button:hover { color: #fff; background: var(--mr-accent); box-shadow: none; }

    /* Separate pagination card. */
    #my-requests-results .mr-footer { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; min-height: 80px; margin-top: 22px; padding: 16px; border: 1px solid var(--mr-line); border-radius: 11px; background: var(--surface-elevated); box-shadow: 0 4px 10px rgba(16, 42, 67, .07); }
    #my-requests-results .mr-footer > p { margin: 0; color: var(--text-secondary); font-size: clamp(12px, 1.25cqw, 15px); line-height: 1.5; }
    #my-requests-results .mr-pagination { display: flex; align-items: center; flex-wrap: wrap; gap: 14px; margin-left: auto; }
    #my-requests-results .mr-page-link { display: inline-flex; align-items: center; justify-content: center; min-width: 38px; height: 40px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 7px; color: var(--text-secondary); background: var(--surface-elevated); font-size: 15px; font-weight: 750; line-height: 1; text-decoration: none; }
    #my-requests-results .mr-page-link > .ui-icon { width: 18px; height: 18px; }
    #my-requests-results .mr-page-link.is-active,
    #my-requests-results a.mr-page-link:hover { color: #fff; background: var(--mr-accent); border-color: var(--mr-accent); }
    #my-requests-results .mr-page-link[aria-disabled="true"] { color: var(--text-muted); background: var(--surface-elevated); cursor: not-allowed; }
    #my-requests-results .mr-page-previous { transform: rotate(180deg); }
    #my-requests-results .mr-page-ellipsis { padding: 0 2px; color: var(--text-muted); }
    #my-requests-results :is(a, button):focus-visible { outline: 2px solid var(--mr-accent); outline-offset: 3px; }

    html[data-theme="dark"] #my-requests-results { --mr-accent: #72b7f4; }
    html[data-theme="dark"] #my-requests-results .mr-row-tile.is-blue,
    html[data-theme="dark"] #my-requests-results .mr-badge.is-blue { background: var(--info-bg); }
    html[data-theme="dark"] #my-requests-results .mr-row-tile.is-violet { color: #bda9f5; background: #211c3a; }
    html[data-theme="dark"] #my-requests-results :is(.mr-page-link.is-active, a.mr-page-link:hover, .mr-row-menu-trigger:hover, .mr-row-menu-trigger[aria-expanded="true"]) { color: #0b203b; }

    /* Empty states */
    .mr-empty {
        display: grid;
        justify-items: center;
        gap: 18px;
        padding: 58px 24px 62px;
        background: var(--surface-elevated);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        text-align: center;
    }

    .mr-empty-art { width: 168px; height: 158px; color: #b9d4f2; }

    .mr-empty-sheet { fill: #eef5fd; stroke: #9dc4ec; stroke-width: 3.4; }
    .mr-empty-clip { fill: #dbe9fa; stroke: #9dc4ec; stroke-width: 3.4; }
    .mr-empty-line rect { fill: #c3daf3; }

    html[data-theme="dark"] .mr-empty-art { color: #2f4a68; }
    html[data-theme="dark"] .mr-empty-sheet { fill: #16273a; stroke: #3d6288; }
    html[data-theme="dark"] .mr-empty-clip { fill: #1d3247; stroke: #3d6288; }
    html[data-theme="dark"] .mr-empty-line rect { fill: #2c455f; }

    .mr-empty-copy { display: grid; gap: 6px; }
    .mr-empty-copy strong { color: var(--heading); font-size: 17px; font-weight: 800; }
    .mr-empty-copy span { color: var(--text-muted); font-size: 13px; }

    .mr-filter-empty { padding: 44px 24px; }
    .mr-filter-empty .mr-empty-art { width: 120px; height: 112px; }

    /* Reflow against the available content width, including an open sidebar. */
    @container my-requests (max-width: 1020px) {
        #my-requests-results .mr-row {
            grid-template-columns: 54px minmax(0, 1fr) minmax(165px, auto) 24px;
            grid-template-areas: "tile identity actions menu" "facts facts facts facts";
            row-gap: 18px;
        }
        #my-requests-results .mr-row-meta { padding-top: 16px; border-top: 1px solid var(--mr-line); }
        #my-requests-results .mr-row-fact:first-child { border-left: 0; padding-left: 0; }
        #my-requests-results .mr-row-fact { min-height: 54px; gap: 9px; }
        #my-requests-results .mr-row-fact-value { font-size: 12px; }
    }
    @container my-requests (max-width: 650px) {
        #my-requests-results { padding: 12px; }
        #my-requests-results .mr-listing-bar { padding: 3px 0 15px; }
        #my-requests-results .mr-row {
            grid-template-columns: 48px minmax(0, 1fr) 24px;
            grid-template-areas: "tile identity menu" "facts facts facts" "actions actions actions";
            gap: 16px 12px;
            padding: 18px 14px;
        }
        #my-requests-results .mr-row-meta { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        #my-requests-results .mr-row-fact { padding: 0 9px; }
        #my-requests-results .mr-row-fact-value { flex-wrap: wrap; }
        #my-requests-results .mr-row-action { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        #my-requests-results .mr-footer { gap: 14px; padding: 14px; }
        #my-requests-results .mr-pagination { gap: 8px; }
    }
    @container my-requests (max-width: 450px) {
        #my-requests-results .mr-sort { margin-left: auto; font-size: 12px; }
        #my-requests-results .mr-sort select { font-size: 13px; min-width: 106px; }
        #my-requests-results .mr-row-meta { grid-template-columns: 1fr; gap: 12px; }
        #my-requests-results .mr-row-fact { border-left: 0; padding: 0; min-height: 0; gap: 5px; }
        #my-requests-results .mr-footer { justify-content: center; }
        #my-requests-results .mr-pagination { margin-left: 0; }
    }
    @media (max-width: 820px) {
        .mr-toolbar { grid-template-columns: 1fr; }
    }
</style>

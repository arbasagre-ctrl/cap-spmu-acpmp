<style>
/*
|--------------------------------------------------------------------------
| Borrower workspace - My Requests
|--------------------------------------------------------------------------
|
| Keep this page on the shared record-list design used by My Borrowings and
| SPMU operational queues. The toolbar comes from .universal-record-toolbar;
| rows come from .operational-record; statuses use the shared status-badge component.
|
*/
.my-requests {
    width: 100%;
    min-width: 0;
}

.my-requests [hidden] { display: none !important; }

/* The shared toolbar already owns sizing, radius, spacing, and field height. */
.my-requests .mr-toolbar { margin-bottom: 16px; }

.mr-results {
    min-width: 0;
}

.mr-results-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin: 0 2px 10px;
    color: var(--text-secondary);
}

.mr-result-count {
    color: var(--heading);
    font-size: 13px;
    font-weight: 800;
}

/* Use the same row density as My Borrowings / Release / Return records. */
.mr-list { gap: 10px; }

/* Request-specific emphasis only; geometry remains the universal record row. */
.mr-list .operational-record-primary > strong { overflow-wrap: anywhere; }
.mr-list .operational-record-primary > span,
.mr-list .operational-record-primary > small { overflow-wrap: anywhere; }
.mr-list .operational-record-facts strong { overflow-wrap: anywhere; }

/* Shared pagination styling, with a lightweight results footer rather than a
   second floating card. */
.mr-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 16px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}

.mr-footer > p {
    margin: 0;
    color: var(--text-muted);
    font-size: 11px;
}

.mr-footer .app-pagination {
    margin-top: 0;
    margin-left: auto;
}

.mr-page-previous { transform: rotate(180deg); }

/* Empty states keep the same card shell and spacing language used elsewhere. */
.mr-empty {
    display: grid;
    justify-items: center;
    gap: 16px;
    min-height: 300px;
    padding: 40px 20px 44px;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    text-align: center;
}

.mr-empty-art {
    width: 156px;
    height: 146px;
    color: #b9d4f2;
}

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

.mr-filter-empty {
    min-height: 260px;
    margin-top: 10px;
    padding: 32px 20px;
}

.mr-filter-empty .mr-empty-art { width: 112px; height: 104px; }

@media (max-width: 760px) {
    .mr-footer { align-items: flex-start; }
    .mr-footer .app-pagination { margin-left: 0; }
}
</style>

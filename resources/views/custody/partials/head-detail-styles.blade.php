<style>
/*
|--------------------------------------------------------------------------
| SPMU custody transaction detail
|--------------------------------------------------------------------------
|
| Scoped to .custody-head-detail so the shared .card / .table-wrap styles
| used elsewhere in the app are left alone.
|
*/

.custody-head-detail { --custody-detail-blue: #0f62d6; width: 100%; min-width: 0; }

/* Card shell: the header sits above a hairline, with no nested grey box. */
.custody-head-detail .card {
    padding: 0;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: var(--surface-elevated);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}

.custody-head-detail .card-header {
    display: block;
    margin: 0;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
}

.custody-head-detail .card-header .eyebrow {
    margin: 0 0 4px;
    color: var(--text-muted);
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: .07em;
}

.custody-head-detail .card-header h2 {
    margin: 0;
    color: var(--heading);
    font-size: 17px;
    font-weight: 750;
    line-height: 1.3;
}

/* Label / value rows */
.custody-head-detail .detail-list {
    grid-template-columns: minmax(140px, .42fr) minmax(0, 1fr);
    padding: 4px 20px 14px;
}

.custody-head-detail .detail-list dt,
.custody-head-detail .detail-list dd {
    padding: 12px 0;
    border-bottom: 1px solid var(--row-border);
}

.custody-head-detail .detail-list dt {
    color: var(--text-muted);
    font-size: 12.5px;
    font-weight: 600;
}

.custody-head-detail .detail-list dd {
    color: var(--heading);
    font-size: 13px;
    font-weight: 650;
    overflow-wrap: anywhere;
}

/* The last pair closes the card rather than drawing a line above its edge. */
.custody-head-detail .detail-list dt:nth-last-of-type(1),
.custody-head-detail .detail-list dd:nth-last-of-type(1) { border-bottom: 0; }

/* Approved property table */
.custody-head-detail .table-wrap { border: 0; border-radius: 0; box-shadow: none; }

.custody-head-detail .table-wrap table { min-width: 720px; }

.custody-head-detail .table-wrap th {
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--table-heading-bg);
    color: var(--text-secondary);
    font-size: 10px;
    font-weight: 750;
    letter-spacing: .06em;
}

.custody-head-detail .table-wrap td {
    padding: 14px 20px;
    border-bottom: 1px solid var(--row-border);
    color: var(--heading);
    font-size: 12.5px;
    vertical-align: middle;
}

.custody-head-detail .table-wrap tbody tr:last-child td { border-bottom: 0; }

.custody-head-detail .table-wrap td strong {
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 700;
}

.custody-head-detail .table-wrap td small {
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 11px;
}

/* Quantities read as a column of figures, the item name stays left. */
.custody-head-detail .table-wrap th:not(:first-child),
.custody-head-detail .table-wrap td:not(:first-child) {
    text-align: center;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.custody-head-detail .table-wrap th:first-child,
.custody-head-detail .table-wrap td:first-child { width: 34%; }

html[data-theme="dark"] .custody-head-detail { --custody-detail-blue: #72b7f4; }

@media (max-width: 700px) {
    .custody-head-detail .card-header { padding: 14px; }
    .custody-head-detail .detail-list { grid-template-columns: minmax(0, 1fr); padding: 4px 14px 12px; }
    .custody-head-detail .detail-list dt { padding-bottom: 0; border-bottom: 0; }
    .custody-head-detail .detail-list dd { padding-top: 2px; }
    .custody-head-detail .table-wrap th,
    .custody-head-detail .table-wrap td { padding-right: 14px; padding-left: 14px; }
}
</style>

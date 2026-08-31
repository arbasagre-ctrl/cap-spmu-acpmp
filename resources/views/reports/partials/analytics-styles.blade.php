<style>
.reporting-analytics .page-heading { margin-bottom: 12px; }
.reporting-analytics .page-heading h1 { font-size: 25px; }
.reporting-analytics .page-heading p:not(.eyebrow) { font-size: 11px; }
.analytics-dashboard { display: grid; gap: 10px; min-width: 0; }
.reporting-workspace .analytics-period-toolbar { display: flex; flex-direction: row; align-items: center; flex-wrap: wrap; gap: 18px; padding: 9px 17px; margin: 0; }
.reporting-workspace .analytics-period-toolbar .report-period-field { display: grid; grid-template-columns: auto minmax(160px, 270px); align-items: center; gap: 28px; font-size: 11px; }
.reporting-workspace .analytics-period-toolbar select { height: 34px; font-size: 11px; }
.analytics-period-toolbar .report-period-context { border-left: 1px solid var(--report-line); padding-left: 20px; font-size: 11px; }
.analytics-period-toolbar .reporting-heading-actions { margin-left: auto; }
.reporting-workspace .analytics-period-toolbar .button { min-height: 34px; font-size: 11px; padding: 7px 16px; }
.analytics-kpi-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; }
.reporting-workspace .analytics-kpi { display: flex; flex-direction: row; align-items: flex-start; gap: 18px; padding: 14px 18px; min-height: 122px; }
.analytics-kpi > div { min-width: 0; }
.reporting-workspace .analytics-kpi h2, .reporting-workspace .analytics-chart-grid h2, .reporting-workspace .analytics-bottom-grid h2 { margin: 0; font-size: 11px; line-height: 1.35; text-transform: uppercase; letter-spacing: .01em; font-weight: 750; color: var(--text-secondary); }
.analytics-kpi h2 { min-height: 17px; }
.analytics-kpi-value { display: block; margin: 3px 0 4px; font-size: 25px; line-height: 1.15; color: var(--heading); }
.analytics-kpi p { margin: 0 0 10px; color: var(--report-muted); font-size: 10px; line-height: 1.45; }
.reporting-workspace .analytics-link { color: var(--report-blue); text-decoration: none; font-size: 10px; font-weight: 700; line-height: 1.5; }
.analytics-link span { margin-left: 3px; }
.reporting-workspace .analytics-link:hover { text-decoration: underline; }
.analytics-secondary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.reporting-workspace .analytics-secondary-stat { display: flex; flex-direction: row; align-items: center; gap: 17px; padding: 7px 16px; min-height: 45px; text-decoration: none; color: var(--text-secondary); font-size: 11px; font-weight: 650; }
.analytics-secondary-stat .reporting-icon { width: 30px; height: 30px; border-radius: 8px; background: var(--surface-elevated); }
.analytics-secondary-stat strong { margin-left: 8px; font-size: 18px; color: var(--heading); }
.analytics-chart-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 1.08fr); gap: 14px; align-items: stretch; }
.reporting-workspace .analytics-chart-grid > .card { padding: 14px 17px; min-height: 186px; }
.analytics-panel-heading { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.analytics-panel-heading p { margin: 3px 0 0; color: var(--report-muted); font-size: 10px; line-height: 1.4; }
.analytics-panel-heading > .analytics-link { flex-shrink: 0; }
.analytics-trend-legend { display: flex; gap: 17px; margin: 12px 0 5px 16px; font-size: 9px; color: var(--text-secondary); font-weight: 650; }
.analytics-trend-legend span { display: flex; align-items: center; gap: 6px; }
.analytics-trend-legend i { width: 9px; height: 9px; border-radius: 2px; background: #0852cc; }
.analytics-trend-legend i.is-released { background: var(--report-green); }
.analytics-trend-layout { display: flex; gap: 6px; min-width: 0; }
.analytics-trend-axis { display: flex; flex-direction: column; justify-content: space-between; width: 10px; height: 82px; padding-top: 2px; font-size: 9px; color: var(--report-muted); }
.analytics-trend-scroll { min-width: 0; flex: 1; overflow-x: auto; }
.analytics-trend-chart { display: grid; grid-template-columns: repeat(var(--trend-columns), minmax(65px, 1fr)); min-width: 100%; }
.analytics-trend-column { min-width: 0; padding-top: 12px; outline-offset: -2px; }
.analytics-trend-column:focus-visible { outline: 2px solid var(--report-blue); }
.analytics-trend-bars { display: flex; align-items: flex-end; justify-content: center; gap: 9px; height: 68px; border-bottom: 1px solid var(--report-line); }
.analytics-trend-bar { display: block; position: relative; width: 18px; border-radius: 3px 3px 0 0; background: linear-gradient(#004ac6, #1462e9); }
.analytics-trend-bar.is-released { background: linear-gradient(#008345, #059550); }
.analytics-trend-bar strong { position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%); font-size: 9px; line-height: 1; color: var(--heading); }
.analytics-trend-label { display: block; text-align: center; margin-top: 7px; font-size: 9px; line-height: 1.35; white-space: nowrap; color: var(--text-secondary); font-weight: 600; }
.analytics-distribution { display: flex; flex-direction: column; gap: 14px; }
.analytics-distribution-rows { display: grid; gap: 12px; }
.analytics-distribution-row > div:first-child { display: flex; justify-content: space-between; gap: 8px; color: var(--heading); font-size: 10px; }
.analytics-distribution-row strong { font-size: 12px; }
.analytics-bar-track { height: 4px; border-radius: 4px; background: var(--surface-hover); margin-top: 5px; overflow: hidden; }
.analytics-bar-track > span { display: block; height: 100%; border-radius: inherit; background: var(--report-blue); }
.analytics-bar-track > .tone-green { background: var(--report-green); }
.analytics-distribution > .analytics-link { margin-top: auto; }
.analytics-bottom-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.055fr); gap: 14px; }
.reporting-workspace .analytics-utilization { padding: 13px 12px 8px; }
.analytics-utilization .analytics-panel-heading { padding: 0 5px 8px; }
.analytics-rankings { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; padding: 12px 14px; border: 1px solid var(--report-line); border-radius: 8px; flex: 1; }
.analytics-ranking + .analytics-ranking { padding-left: 14px; border-left: 1px solid var(--report-line); }
.reporting-workspace .analytics-ranking h3 { font-size: 9px; text-transform: uppercase; font-weight: 750; margin: 0 0 4px; color: var(--text-secondary); }
.analytics-ranking ol { padding: 0; margin: 0; list-style: none; counter-reset: ranking; }
.analytics-ranking li { position: relative; display: flex; align-items: baseline; gap: 8px; padding: 10px 0 10px 15px; border-bottom: 1px solid var(--report-line); font-size: 9px; counter-increment: ranking; }
.analytics-ranking li:last-child { border-bottom: 0; }
.analytics-ranking li::before { content: counter(ranking) "."; position: absolute; left: 0; color: var(--text-secondary); }
.analytics-ranking li a { color: var(--text-secondary); text-decoration: none; font-weight: 650; flex: 1; min-width: 0; }
.analytics-ranking li a:hover { color: var(--report-blue); }
.analytics-ranking li strong { color: var(--heading); white-space: nowrap; font-size: 8px; }
.reporting-workspace .analytics-ranking-empty { list-style: none; padding-left: 0; color: var(--report-muted); font-weight: 400; }
.analytics-ranking-empty::before { display: none; }
.reporting-workspace .analytics-insights { padding: 13px 7px 7px; }
.analytics-insights > h2 { padding: 0 10px 7px; }
.analytics-insights-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px 12px; flex: 1; }
.analytics-insight { min-width: 0; display: flex; flex-direction: column; padding: 9px 11px; border: 1px solid var(--report-line); border-radius: 10px; }
.analytics-insight-heading { display: flex; align-items: center; gap: 9px; }
.analytics-insight-heading .reporting-icon { width: 27px; height: 27px; }
.reporting-workspace .analytics-insight h3 { margin: 0; color: var(--text-secondary); font-size: 9px; font-weight: 750; text-transform: uppercase; line-height: 1.4; }
.analytics-insight-heading p { margin: 0; color: var(--text-secondary); font-size: 9px; line-height: 1.4; }
.analytics-insight > .analytics-link { margin-top: auto; padding-top: 5px; font-size: 9px; }
.analytics-insight-content { padding-top: 8px; }
.analytics-insight-content p { color: var(--heading); font-size: 10px; line-height: 1.5; margin: 0 0 4px; }
.analytics-borrowers { margin: 6px 0 0; padding-left: 17px; font-size: 9px; font-weight: 700; color: var(--heading); }
.analytics-borrowers li { padding: 3px 0; }
.analytics-borrowers li > span { display: inline-block; max-width: 70%; vertical-align: top; }
.analytics-borrowers strong { float: right; font-size: 8px; }
.analytics-inventory-snapshot { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 8px 0 0; }
.analytics-inventory-snapshot > div { display: flex; flex-direction: column-reverse; justify-content: flex-end; align-items: center; gap: 2px; min-width: 0; padding: 0 3px; }
.analytics-inventory-snapshot > div + div { border-left: 1px solid var(--report-line); }
.analytics-inventory-snapshot dt { text-align: center; font-size: 8px; font-weight: 500; color: var(--text-secondary); }
.analytics-inventory-snapshot dd { margin: 0; font-size: 16px; line-height: 1.2; font-weight: 750; color: var(--heading); }
.analytics-laundry-count { margin: 6px 0 0; color: var(--report-muted); font-size: 9px; }
.analytics-empty { margin: 20px 0; font-size: 11px; color: var(--report-muted); }
.reporting-workspace .analytics-formulas { padding: 0; }
.analytics-formulas summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; cursor: pointer; padding: 11px 16px; font-size: 12px; font-weight: 700; color: var(--heading); list-style: none; }
.analytics-formulas summary::-webkit-details-marker { display: none; }
.analytics-formulas[open] summary > svg { transform: rotate(180deg); }
.analytics-formula-body { padding: 0 16px 14px; color: var(--report-muted); font-size: 11px; }
.analytics-formula-body p { margin: 8px 0; }
.analytics-status-links { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 8px; }
.request-status-results { scroll-margin-top: 90px; }
.request-status-results-header, .request-status-results-footer { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
.request-status-results-header h3 { font-size: 17px; }
.request-status-results-header p { color: var(--report-muted); font-size: 11px; }
.request-status-table-wrap { overflow-x: auto; }
.request-status-table { min-width: 750px; }
.request-status-table td { font-size: 11px; }
.request-status-table td small { display: block; }
.request-status-results-footer { margin-top: 12px; }
@media (max-width: 1250px) {
    .reporting-workspace .analytics-kpi { padding: 14px 12px; gap: 10px; }
    .analytics-kpi .reporting-icon { width: 34px; height: 34px; }
    .analytics-chart-grid { grid-template-columns: 1fr 1fr; }
    .analytics-chart-grid .analytics-trend { grid-column: 1 / -1; }
    .analytics-bottom-grid { grid-template-columns: 1fr; }
}
@media (max-width: 1000px) {
    .analytics-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .analytics-period-toolbar .reporting-heading-actions { margin-left: 0; }
}
@media (max-width: 650px) {
    .analytics-kpi-grid, .analytics-secondary-grid, .analytics-chart-grid { grid-template-columns: 1fr; }
    .analytics-chart-grid .analytics-trend { grid-column: auto; }
    .reporting-workspace .analytics-period-toolbar { display: grid; gap: 12px; }
    .reporting-workspace .analytics-period-toolbar .report-period-field { grid-template-columns: 1fr; gap: 7px; }
    .analytics-period-toolbar .report-period-context { border-left: 0; padding-left: 0; }
    .analytics-rankings, .analytics-insights-grid { grid-template-columns: 1fr; }
    .analytics-ranking + .analytics-ranking { padding-left: 0; border-left: 0; border-top: 1px solid var(--report-line); padding-top: 12px; }
    .analytics-panel-heading { flex-wrap: wrap; }
}
</style>

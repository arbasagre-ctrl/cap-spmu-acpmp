<style>
.sanction-rules-config { display: grid; gap: 14px; min-width: 0; }
.sanction-rules-config [hidden] { display: none !important; }
.sanction-rules-config .sanction-offense-card { padding: 20px 22px; }

/* Offense application: intent copy beside the eligible case types. */
.sanction-rules-config .offense-application-panel { display: grid; grid-template-columns: minmax(240px, .8fr) minmax(0, 2.2fr); gap: 22px; margin: 0; padding: 0; border: 0; border-radius: 0; background: none; }
.sanction-rules-config .offense-application-copy { display: block; align-content: start; }
.sanction-rules-config .offense-application-icon { display: grid; place-items: center; width: 50px; height: 50px; margin-bottom: 16px; border-radius: 13px; background: var(--blue-50); color: var(--interactive); }
.sanction-rules-config .offense-application-copy .eyebrow { margin-bottom: 8px; color: var(--interactive); }
.sanction-rules-config .offense-application-copy h2 { margin: 0 0 12px; font-size: 17px; line-height: 1.4; }
.sanction-rules-config .offense-application-copy p { margin: 0; color: var(--text-muted); font-size: 12.5px; line-height: 1.65; }

.sanction-rules-config .offense-application-form { display: grid; align-content: start; gap: 12px; }
.sanction-rules-config .offense-type-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.sanction-rules-config .offense-type-option { display: flex !important; align-items: flex-start; gap: 14px !important; margin: 0 !important; padding: 12px 14px !important; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); color: var(--text) !important; cursor: pointer; font-weight: 400 !important; transition: border-color var(--motion) ease, box-shadow var(--motion) ease; }
.sanction-rules-config .offense-type-option:hover { border-color: var(--border-strong); box-shadow: var(--shadow-sm); }
.sanction-rules-config .offense-type-option:has(input[type="checkbox"]:focus-visible) { border-color: var(--interactive); box-shadow: var(--focus-ring); }
.sanction-rules-config .offense-type-option input[type="checkbox"] { width: 19px !important; height: 19px; min-height: 19px; flex: 0 0 auto; margin: 1px 0 0 !important; accent-color: var(--interactive); }
.sanction-rules-config .offense-type-icon { display: grid; flex: 0 0 auto; place-items: center; width: 38px; height: 38px; border-radius: 10px; background: var(--blue-50); color: var(--interactive); }
.sanction-rules-config .offense-type-copy { display: grid; gap: 4px; min-width: 0; }
.sanction-rules-config .offense-type-copy strong { color: var(--heading); font-size: 13px; font-weight: 750; line-height: 1.35; }
.sanction-rules-config .offense-type-copy small { color: var(--text-muted); font-size: 11.5px; line-height: 1.5; }
.sanction-rules-config .offense-application-save { justify-self: start; min-height: 40px; }


/* Per-offense default rules. */
.sanction-rules-config .sanction-rule-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); align-items: stretch; gap: 16px; }
.sanction-rules-config .sanction-rule-card { display: flex; min-width: 0; flex-direction: column; gap: 0; padding: 18px 20px 20px; background: var(--surface-elevated); }
.sanction-rules-config .sanction-rule-title { display: flex; align-items: center; gap: 10px; margin: 0 0 14px; font-size: 15px; font-weight: 750; }
.sanction-rules-config .sanction-rule-badge { display: grid; flex: 0 0 auto; place-items: center; width: 28px; height: 28px; border-radius: 999px; background: var(--primary-action); color: #fff; font-size: 12px; font-weight: 800; }
.sanction-rules-config .sanction-rule-card label { display: grid; gap: 6px; margin: 0 0 12px; color: var(--text-secondary); font-size: 12.5px; font-weight: 700; }
.sanction-rules-config .sanction-rule-card select, .sanction-rules-config .sanction-rule-card input { min-height: 44px; border-color: var(--border); border-radius: 8px; font-weight: 400; }
.sanction-rules-config .sanction-rule-save { width: 100%; gap: 9px; margin-top: 14px; min-height: 42px; padding: 9px 18px; }
.sanction-rules-config .sanction-rule-save .ui-icon { flex-shrink: 0; }

.sanction-rules-config .sanction-result { display:grid; gap:3px; margin:0 0 10px; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:var(--surface-subtle); }
.sanction-rules-config .sanction-result span { color:var(--text-muted); font-size:10.5px; font-weight:750; letter-spacing:.05em; text-transform:uppercase; }
.sanction-rules-config .sanction-result strong { color:var(--heading); font-size:12.5px; line-height:1.35; }
.sanction-rules-config .sanction-advanced { margin:0; }
.sanction-rules-config .sanction-advanced summary { color:var(--interactive); font-size:11.5px; font-weight:700; cursor:pointer; }
.sanction-rules-config .sanction-advanced[open] summary { margin-bottom:10px; }
.sanction-rules-config .sanction-advanced label { margin-bottom:0; }

/* Compact policy-history helper. */
.sanction-rules-config .sanction-policy-history { display:flex; align-items:center; justify-content:center; gap:7px; margin:0; color:var(--text-muted); font-size:11.5px; line-height:1.45; text-align:center; }
.sanction-rules-config .sanction-policy-history > .ui-icon { flex-shrink:0; color:var(--text-muted); }


/* Dark mode recesses the nested tiles instead of lifting them. */
html[data-theme="dark"] .sanction-rules-config .offense-type-option { background: var(--surface-subtle); }
html[data-theme="dark"] .sanction-rules-config .offense-type-option:hover { background: var(--surface-muted); }

@media (max-width: 1180px) {
    .sanction-rules-config .offense-application-panel { grid-template-columns: minmax(0, 1fr); gap: 20px; }
    .sanction-rules-config .sanction-rule-grid { grid-template-columns: minmax(0, 1fr); }
}

@media (max-width: 720px) {
    .sanction-rules-config .sanction-offense-card { padding: 18px; }
    .sanction-rules-config .offense-type-grid { grid-template-columns: minmax(0, 1fr); }
    .sanction-rules-config .offense-application-save { justify-self: stretch; }
}

@media (prefers-reduced-motion: reduce) {
    .sanction-rules-config .offense-type-option { transition: none; }
}
</style>

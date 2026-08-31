<style>
.inventory-form-page { --inventory-blue: #0865df; width: 100%; min-width: 0; font-size: 13px; }
.inventory-form-page .inventory-form-heading { align-items: flex-end; flex-wrap: wrap; gap: 14px 22px; margin-bottom: 22px; }
.inventory-form-heading .eyebrow { margin-bottom: 10px; }
.inventory-form-page .inventory-form-heading h1 { margin: 0; }
.inventory-form-page .inventory-form-back { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; padding: 6px 2px; color: var(--inventory-blue); font-size: 13px; font-weight: 700; text-decoration: none; }
.inventory-form-page .inventory-form-back:hover, .inventory-form-page .inventory-form-back:focus-visible { text-decoration: underline; }
.inventory-form-back .ui-icon { flex-shrink: 0; transform: rotate(180deg); }

.inventory-form-page .inventory-form-card { display: grid; gap: 18px; padding: 26px 28px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface-elevated); }
.inventory-form-page .inventory-form-card > label,
.inventory-form-columns > label { display: grid; gap: 8px; margin: 0; color: var(--text-secondary); font-size: 12px; font-weight: 700; }
.inventory-form-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
.inventory-form-page input, .inventory-form-page select, .inventory-form-page textarea { width: 100%; min-height: 44px; border-radius: 7px; font-size: 13px; }
.inventory-form-page textarea { min-height: 96px; padding: 11px; line-height: 1.6; resize: vertical; }
.inventory-form-page textarea[name="change_reason"] { min-height: 84px; }

.inventory-form-page fieldset { min-width: 0; margin: 0; padding: 16px 18px 18px; border: 1px solid var(--border); border-radius: 9px; background: var(--surface-elevated); }
.inventory-form-page legend { width: auto; padding: 0 6px 0 0; margin-left: -2px; color: var(--heading); font-size: 12px; font-weight: 750; }
.inventory-form-flags { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); align-items: stretch; gap: 12px; }
.inventory-form-page .inventory-form-flags label { display: flex; align-items: center; gap: 10px; min-height: 48px; margin: 0; padding: 10px 13px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface-subtle); color: var(--heading); font-size: 12px; font-weight: 650; line-height: 1.4; cursor: pointer; }
.inventory-form-page .inventory-form-flags input[type="checkbox"] { width: 17px; min-height: 0; height: 17px; flex-shrink: 0; margin: 0; accent-color: var(--inventory-blue); cursor: pointer; }
.inventory-form-page .inventory-form-flags label:hover { border-color: var(--border-strong); }
.inventory-form-page .inventory-form-flags label:focus-within { border-color: var(--inventory-blue); box-shadow: var(--focus-ring); }

.inventory-form-actions { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 10px; margin-top: 4px; }
.inventory-form-page .button.inventory-form-cancel { min-height: 42px; padding: 11px 24px; border-color: var(--inventory-blue); border-radius: 7px; background: var(--surface-elevated); color: var(--inventory-blue); font-size: 13px; }
.inventory-form-page .button.inventory-form-cancel:hover, .inventory-form-page .button.inventory-form-cancel:focus-visible { background: var(--info-bg); }
.inventory-form-page .button.inventory-form-save { min-height: 42px; padding: 11px 26px; border-color: var(--inventory-blue); border-radius: 7px; background: var(--inventory-blue); color: #fff; font-size: 13px; }
.inventory-form-page .button.inventory-form-save:hover, .inventory-form-page .button.inventory-form-save:focus-visible { border-color: #0a56b8; background: #0a56b8; color: #fff; }

html[data-theme="dark"] .inventory-form-page { --inventory-blue: #72b7f4; }
html[data-theme="dark"] .inventory-form-page .button.inventory-form-save,
html[data-theme="dark"] .inventory-form-page .button.inventory-form-save:hover { color: var(--navy-950); }

@media (max-width: 760px) {
    .inventory-form-page .inventory-form-heading { align-items: stretch; }
    .inventory-form-columns { grid-template-columns: minmax(0, 1fr); }
    .inventory-form-page .inventory-form-card { padding: 18px 16px; }
    .inventory-form-actions { justify-content: stretch; }
    .inventory-form-page .button.inventory-form-cancel, .inventory-form-page .button.inventory-form-save { flex: 1 1 auto; justify-content: center; }
}
</style>

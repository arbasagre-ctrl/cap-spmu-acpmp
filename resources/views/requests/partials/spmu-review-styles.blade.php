<style>
/*
|--------------------------------------------------------------------------
| SPMU Head - request review workspace
|--------------------------------------------------------------------------
|
| One stylesheet for the whole review page. It replaces the layered override
| blocks this page had accumulated, so a single place decides the review
| layout instead of several competing ones.
|
| Sections, in order:
|   1. Workspace layout        4. Verification checklist and decision
|   2. Scanned document panel  5. Confirmation dialog
|   3. Request details grid    6. Responsive
|
*/

/* 0. Progress tracker ----------------------------------------------------- */

/*
 * The shared tracker reserves a fixed rail width, which leaves a short
 * horizontal scrollbar on a 1366px screen. On this page the eight stages
 * are allowed to share the available width instead.
 */
@media (min-width: 1100px) {
    .request-tracker__rail {
        min-width: 0;
        grid-template-columns: repeat(8, minmax(0, 1fr));
    }

    .request-tracker__step { padding-inline: 5px; }

    .request-tracker__copy small { max-width: 100%; }
}

/* 1. Workspace layout ---------------------------------------------------- */

.spmu-verification-workspace {
    --spmu-gap: 18px;
}

.spmu-review-layout {
    display: grid;
    gap: var(--spmu-gap);
    width: 100%;
    min-width: 0;
}

.spmu-review-layout > * { min-width: 0; }

.spmu-review-top-row {
    display: grid;
    grid-template-columns: minmax(0, 53fr) minmax(0, 47fr);
    gap: var(--spmu-gap);
    align-items: stretch;
}

.spmu-review-top-row > * { min-width: 0; }

/* Panels that manage their own internal padding per region. The inventory
   card below keeps the standard card padding. */
.spmu-scan-slot > .scanned-document-card,
.spmu-verification-workspace .spmu-checklist-panel,
.spmu-verification-workspace .spmu-left-borrowing-info {
    padding: 0;
    overflow: hidden;
}

.spmu-verification-workspace .card-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin: 0;
    padding: 15px 20px;
    border-bottom: 1px solid var(--border);
}

.spmu-verification-workspace .card-header .eyebrow {
    margin: 0 0 3px;
    font-size: 11px;
    letter-spacing: .06em;
}

.spmu-verification-workspace .card-header h2 {
    margin: 0;
    font-size: 16px;
    line-height: 1.3;
}

/* 2. Scanned document panel ---------------------------------------------- */

.spmu-scan-slot {
    display: flex;
    min-width: 0;
}

.spmu-scan-slot > .scanned-document-card {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    width: 100%;
    min-width: 0;
}

.spmu-scan-slot .scanned-document-header {
    flex: 0 0 auto;
    padding: 15px 20px;
}

.spmu-scan-slot .scanned-document-header h2 {
    margin: 3px 0 0;
    font-size: 16px;
}

/*
 * Only the stage is sized. The document inside keeps its own scrolling, so
 * the full page - signatures, footer and all - stays reachable. It is never
 * clipped with overflow:hidden.
 */
.spmu-scan-slot .scanned-pdf-stage,
.spmu-scan-slot .scanned-image-stage {
    flex: 1 1 auto;
    height: auto;
    min-height: clamp(560px, 64vh, 720px);
}

.spmu-scan-slot .scanned-image-viewer {
    display: flex;
    flex: 1 1 auto;
    min-height: 0;
    flex-direction: column;
}

.spmu-scan-slot .scanned-image-stage { overflow: auto; }

.spmu-scan-slot .scanned-pdf-frame {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
}

/* 3. Request details grid ------------------------------------------------ */

.spmu-left-borrowing-info {
    display: flex;
    flex-direction: column;
}

.spmu-left-borrowing-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    align-content: start;
}

.spmu-left-borrowing-cell {
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    gap: 4px;
    min-width: 0;
    padding: 13px 20px;
    border-right: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.spmu-left-borrowing-cell:last-child { border-right: 0; }

.spmu-left-borrowing-cell span {
    color: var(--text-muted);
    font-size: 11.5px;
    font-weight: 650;
    line-height: 1.25;
}

.spmu-left-borrowing-cell strong {
    color: var(--heading);
    font-size: 13.5px;
    font-weight: 700;
    line-height: 1.4;
    overflow-wrap: anywhere;
    word-break: normal;
}

/* 4. Verification checklist and decision --------------------------------- */

.spmu-checklist-panel {
    display: flex;
    flex-direction: column;
}

.spmu-checklist-panel > .empty-state { margin: 18px 20px; }

.spmu-review-summary {
    margin: 15px 20px 0;
    color: var(--text-muted);
    font-size: 12.5px;
    line-height: 1.5;
}

.spmu-supporting-document {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    margin: 14px 20px 0;
    padding: 11px 13px;
    background: var(--surface-subtle);
    border: 1px solid var(--border);
    border-radius: 9px;
}

.spmu-supporting-document > div { display: grid; gap: 2px; }

.spmu-supporting-document strong {
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 700;
}

.spmu-supporting-document small,
.spmu-check-row small {
    color: var(--text-muted);
    font-size: 11.5px;
}

.spmu-verification-form {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    margin: 0;
    padding: 0 20px 18px;
}

.spmu-checklist {
    display: grid;
    gap: 8px;
    margin: 15px 0 0;
}

.spmu-check-row {
    display: grid;
    grid-template-columns: 18px minmax(0, 1fr);
    gap: 11px;
    align-items: start;
    padding: 11px 13px;
    border: 1px solid var(--border);
    border-radius: 9px;
    cursor: pointer;
    transition: border-color var(--motion) ease, background-color var(--motion) ease;
}

.spmu-check-row:hover { border-color: var(--border-strong); }

.spmu-check-row:has(input:checked) {
    border-color: var(--success-border);
    background: var(--success-bg);
}

.spmu-check-row input {
    width: 17px;
    height: 17px;
    margin: 1px 0 0;
}

.spmu-check-row span { display: grid; gap: 2px; }

.spmu-check-row strong {
    color: var(--heading);
    font-size: 12.5px;
    font-weight: 650;
    line-height: 1.4;
    overflow-wrap: anywhere;
    word-break: normal;
}

.spmu-verification-workspace .field-error {
    margin: 12px 0 0;
    color: var(--danger);
    font-size: 12px;
    line-height: 1.45;
}

/*
 * The decision block sits at the foot of the card so the two review panels
 * finish on the same line on a desktop screen.
 */
.spmu-review-footer {
    margin-top: auto;
    padding-top: 16px;
}

.spmu-decision-actions {
    display: grid;
    gap: 8px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}

.spmu-decision-actions .button {
    width: 100%;
    min-height: 40px;
    font-size: 12.5px;
}

.spmu-decision-actions .button:disabled {
    opacity: .5;
    cursor: not-allowed;
    transform: none;
}

/*
 * Decision hover states
 * ---------------------
 * Each decision announces itself in its own colour only while the pointer is
 * on it (or it is keyboard-focused); the resting state is left exactly as the
 * page already renders it.
 *
 * !important is required here, and only here: app.css carries a generic
 * `button:not(...):not(...)...:hover` rule whose long :not() chain gives it a
 * specificity no reasonable selector in this file can outrank.
 */
.spmu-decision-actions .button {
    transition:
        background-color 170ms ease,
        border-color 170ms ease,
        color 170ms ease;
}

.spmu-decision-actions .button[data-decision-trigger="APPROVED"]:hover:not(:disabled),
.spmu-decision-actions .button[data-decision-trigger="APPROVED"]:focus-visible:not(:disabled),
.spmu-decision-actions .button[data-decision-trigger="VERIFIED"]:hover:not(:disabled),
.spmu-decision-actions .button[data-decision-trigger="VERIFIED"]:focus-visible:not(:disabled) {
    color: #fff !important;
    background-color: #16a34a !important;
    border-color: #16a34a !important;
}

.spmu-decision-actions .button[data-decision-trigger="RETURNED_FOR_REVISION"]:hover:not(:disabled),
.spmu-decision-actions .button[data-decision-trigger="RETURNED_FOR_REVISION"]:focus-visible:not(:disabled) {
    color: #fff !important;
    background-color: #d97706 !important;
    border-color: #d97706 !important;
}

.spmu-decision-actions .button[data-decision-trigger="REJECTED"]:hover:not(:disabled),
.spmu-decision-actions .button[data-decision-trigger="REJECTED"]:focus-visible:not(:disabled) {
    color: #fff !important;
    background-color: #dc2626 !important;
    border-color: #dc2626 !important;
}

/* The focus ring stays on top of the fill so keyboard position is obvious. */
.spmu-decision-actions .button:focus-visible {
    outline: 2px solid var(--surface-elevated);
    outline-offset: -4px;
    box-shadow: var(--focus-ring);
}

html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="APPROVED"]:hover:not(:disabled),
html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="APPROVED"]:focus-visible:not(:disabled),
html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="VERIFIED"]:hover:not(:disabled),
html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="VERIFIED"]:focus-visible:not(:disabled) {
    color: #fff !important;
    background-color: #22c55e !important;
    border-color: #22c55e !important;
}

/* Dark amber is too light to carry white text, so this one takes dark ink. */
html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="RETURNED_FOR_REVISION"]:hover:not(:disabled),
html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="RETURNED_FOR_REVISION"]:focus-visible:not(:disabled) {
    color: #111827 !important;
    background-color: #f59e0b !important;
    border-color: #f59e0b !important;
}

html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="REJECTED"]:hover:not(:disabled),
html[data-theme="dark"] .spmu-decision-actions .button[data-decision-trigger="REJECTED"]:focus-visible:not(:disabled) {
    color: #fff !important;
    background-color: #ef4444 !important;
    border-color: #ef4444 !important;
}

/* 5. Confirmation dialog -------------------------------------------------- */

.spmu-confirm-dialog {
    position: fixed;
    top: 50%;
    left: 50%;
    right: auto;
    bottom: auto;
    z-index: 10000;
    width: min(520px, calc(100vw - 32px));
    max-height: calc(100dvh - 32px);
    margin: 0;
    padding: 0;
    overflow: auto;
    background: var(--surface-elevated);
    border: 1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 22px 60px rgba(15, 23, 42, .22);
    transform: translate(-50%, -50%);
}

.spmu-confirm-dialog::backdrop { background: rgba(15, 23, 42, .5); }

.spmu-confirm-dialog__surface {
    display: grid;
    grid-template-columns: 40px minmax(0, 1fr);
    gap: 15px;
    padding: 22px;
}

.spmu-confirm-dialog__surface h2 {
    margin: 1px 0 6px;
    font-size: 16px;
}

.spmu-confirm-dialog__icon {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    color: var(--warning);
    background: var(--warning-bg);
    border-radius: 50%;
    font-size: 1.1rem;
    font-weight: 800;
}

.spmu-confirm-dialog__icon--danger { color: var(--danger); background: var(--danger-bg); }

.spmu-dialog-remarks {
    display: grid;
    gap: 7px;
    margin-top: 14px;
}

.spmu-dialog-remarks textarea {
    width: 100%;
    resize: vertical;
}

.spmu-confirm-dialog__actions {
    grid-column: 1 / -1;
    display: flex;
    justify-content: flex-end;
    gap: 9px;
    margin-top: 2px;
}

/* 6. Responsive ----------------------------------------------------------- */

@media (max-width: 1180px) {
    .spmu-review-top-row { grid-template-columns: 1fr; }

    .spmu-scan-slot .scanned-pdf-stage,
    .spmu-scan-slot .scanned-image-stage {
        min-height: clamp(460px, 60vh, 620px);
    }
}

@media (max-width: 620px) {
    .spmu-left-borrowing-grid { grid-template-columns: 1fr; }
    .spmu-left-borrowing-cell { border-right: 0; }

    .spmu-supporting-document,
    .spmu-confirm-dialog__actions {
        align-items: stretch;
        flex-direction: column;
    }

    .spmu-confirm-dialog__actions .button { width: 100%; }
}
</style>

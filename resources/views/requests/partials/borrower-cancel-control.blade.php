@php
    $cancelTriggerClass = $cancelTriggerClass ?? 'button secondary ui-pressable borrower-cancel-trigger is-danger';
    $cancelTriggerLabel = $cancelTriggerLabel ?? 'Cancel Request';
    $cancelDialogTitle = $cancelDialogTitle ?? 'Cancel this borrowing request?';
    $cancelDialogCopy = $cancelDialogCopy
        ?? 'This request will be cancelled immediately. Cancellation is allowed only before physical release. Any approved but unreleased allocation returns to Available inventory and any pending pickup documents will be invalidated.';
    $cancelReasonPlaceholder = $cancelReasonPlaceholder
        ?? 'Briefly explain why this request is being cancelled...';
    $cancelReasonId = 'borrower-cancellation-reason-'.($borrowingRequest->id ?? 'request');
@endphp

<div class="borrower-cancel-control" data-request-cancel-workspace>
    <button
        class="{{ $cancelTriggerClass }}"
        type="button"
        data-request-cancel-trigger
    >
        {{ $cancelTriggerLabel }}
    </button>

    <form
        method="post"
        action="{{ route('requests.cancel', $borrowingRequest) }}"
        data-request-cancel-form
        hidden
    >
        @csrf
        <input type="hidden" name="reason" value="" data-request-cancel-reason>
    </form>

    <dialog class="spmu-confirm-dialog borrower-cancel-dialog" data-request-cancel-dialog>
        <div class="spmu-confirm-dialog__surface">
            <div class="spmu-confirm-dialog__icon spmu-confirm-dialog__icon--danger" aria-hidden="true">!</div>

            <div>
                <h2>{{ $cancelDialogTitle }}</h2>
                <p class="meta">{{ $cancelDialogCopy }}</p>

                <div class="spmu-dialog-remarks">
                    <label for="{{ $cancelReasonId }}">Cancellation reason *</label>
                    <textarea
                        id="{{ $cancelReasonId }}"
                        rows="4"
                        maxlength="500"
                        data-request-cancel-reason-field
                        placeholder="{{ $cancelReasonPlaceholder }}"
                    ></textarea>
                    <div class="spmu-dialog-remarks__footer">
                        <small>This reason will be recorded in the request history.</small>
                        <small class="spmu-dialog-remarks__counter" data-request-cancel-counter>0 / 500</small>
                    </div>
                    <p class="field-error" data-request-cancel-error hidden></p>
                </div>
            </div>

            <div class="spmu-confirm-dialog__actions">
                <button class="button secondary ui-pressable" type="button" data-request-cancel-back>Keep Request</button>
                <button class="button danger ui-pressable" type="button" data-request-cancel-confirm>Cancel Request</button>
            </div>
        </div>
    </dialog>
</div>

@once
<style>
.borrower-cancel-control {
    display: inline-flex;
    align-items: center;
    margin: 0;
}

.borrower-cancel-trigger.is-danger {
    border-color: var(--danger-border) !important;
    background: var(--surface-elevated) !important;
    color: var(--danger) !important;
}

.borrower-cancel-trigger.is-danger:hover,
.borrower-cancel-trigger.is-danger:focus-visible {
    border-color: var(--danger-action) !important;
    background: var(--danger-action) !important;
    color: #fff !important;
}

dialog[data-request-cancel-dialog] {
    position: fixed !important;
    top: 50% !important;
    left: 50% !important;
    right: auto !important;
    bottom: auto !important;
    transform: translate(-50%, -50%) !important;
    width: min(560px, calc(100vw - 32px)) !important;
    max-width: 560px !important;
    max-height: min(680px, calc(100dvh - 40px)) !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
    border: 1px solid #d8e1eb !important;
    border-radius: 16px !important;
    background: #fff !important;
    box-shadow: 0 24px 60px rgba(15, 23, 42, .22), 0 8px 24px rgba(15, 23, 42, .12) !important;
    z-index: 99999 !important;
}

dialog[data-request-cancel-dialog]::backdrop {
    background: rgba(10, 27, 47, .48) !important;
    backdrop-filter: blur(2px);
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__surface {
    display: grid !important;
    grid-template-columns: 42px minmax(0, 1fr) !important;
    gap: 14px !important;
    width: 100% !important;
    max-height: min(680px, calc(100dvh - 40px)) !important;
    padding: 22px !important;
    box-sizing: border-box !important;
    overflow-y: auto !important;
    background: #fff !important;
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__icon {
    display: grid !important;
    place-items: center !important;
    width: 42px !important;
    height: 42px !important;
    margin: 0 !important;
    border-radius: 50% !important;
    background: #fff1f0 !important;
    color: #b42318 !important;
    font-size: 18px !important;
    font-weight: 800 !important;
}

dialog[data-request-cancel-dialog] h2 {
    margin: 1px 0 7px !important;
    color: #102a43 !important;
    font-size: 20px !important;
    line-height: 1.3 !important;
}

dialog[data-request-cancel-dialog] h2 + .meta {
    margin: 0 !important;
    color: #63768a !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks { margin-top: 18px !important; }

dialog[data-request-cancel-dialog] .spmu-dialog-remarks label {
    display: block !important;
    margin-bottom: 7px !important;
    color: #30445a !important;
    font-size: 13px !important;
    font-weight: 700 !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks textarea {
    display: block !important;
    width: 100% !important;
    min-height: 120px !important;
    max-height: 220px !important;
    padding: 11px 12px !important;
    box-sizing: border-box !important;
    resize: vertical !important;
    border: 1px solid #b8c6d5 !important;
    border-radius: 9px !important;
    background: #fff !important;
    color: #102a43 !important;
    font: inherit !important;
    font-size: 13px !important;
    line-height: 1.5 !important;
    outline: none !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks textarea:focus {
    border-color: #1769e0 !important;
    box-shadow: 0 0 0 3px rgba(23, 105, 224, .12) !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks__footer {
    display: flex !important;
    align-items: baseline !important;
    justify-content: space-between !important;
    gap: 12px !important;
    margin-top: 6px !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks small {
    display: block !important;
    color: #708196 !important;
    font-size: 11.5px !important;
    line-height: 1.4 !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks__counter {
    flex-shrink: 0 !important;
    color: #8394a6 !important;
    font-variant-numeric: tabular-nums !important;
}

dialog[data-request-cancel-dialog] .spmu-dialog-remarks__counter[data-limit-near="1"] {
    color: #b42318 !important;
    font-weight: 700 !important;
}

dialog[data-request-cancel-dialog] .field-error {
    margin-top: 6px !important;
    color: #b42318 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions {
    grid-column: 1 / -1 !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 9px !important;
    margin-top: 4px !important;
    padding-top: 16px !important;
    border-top: 1px solid #e4eaf0 !important;
}

dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions .button {
    min-height: 38px !important;
    padding: 8px 14px !important;
    border-radius: 8px !important;
}

@media (max-width: 620px) {
    dialog[data-request-cancel-dialog] {
        width: calc(100vw - 24px) !important;
        max-height: calc(100dvh - 24px) !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__surface {
        grid-template-columns: 38px minmax(0, 1fr) !important;
        max-height: calc(100dvh - 24px) !important;
        gap: 12px !important;
        padding: 18px !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__icon {
        width: 38px !important;
        height: 38px !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions {
        flex-direction: column-reverse !important;
        align-items: stretch !important;
    }

    dialog[data-request-cancel-dialog] .spmu-confirm-dialog__actions .button {
        width: 100% !important;
        justify-content: center !important;
    }
}
</style>

<script>
(() => {
    const initializeBorrowerRequestCancellation = () => {
        document.querySelectorAll('[data-request-cancel-workspace]').forEach((workspace) => {
            if (workspace.dataset.cancelInitialized === '1') {
                return;
            }

            const trigger = workspace.querySelector('[data-request-cancel-trigger]');
            const dialog = workspace.querySelector('[data-request-cancel-dialog]');
            const form = workspace.querySelector('[data-request-cancel-form]');
            const hiddenReason = workspace.querySelector('[data-request-cancel-reason]');
            const reasonField = workspace.querySelector('[data-request-cancel-reason-field]');
            const error = workspace.querySelector('[data-request-cancel-error]');
            const back = workspace.querySelector('[data-request-cancel-back]');
            const confirm = workspace.querySelector('[data-request-cancel-confirm]');
            const counter = workspace.querySelector('[data-request-cancel-counter]');

            if (!trigger || !dialog || !form || !hiddenReason || !reasonField || !confirm) {
                return;
            }

            workspace.dataset.cancelInitialized = '1';
            const limit = Number(reasonField.getAttribute('maxlength')) || 500;

            const updateCounter = () => {
                if (!counter) return;
                const used = reasonField.value.length;
                counter.textContent = used + ' / ' + limit;
                counter.dataset.limitNear = used >= limit ? '1' : '0';
            };

            const clearError = () => {
                if (!error) return;
                error.textContent = '';
                error.hidden = true;
            };

            const closeDialog = () => {
                clearError();
                if (dialog.open && typeof dialog.close === 'function') dialog.close();
            };

            const submitCancellation = (reason) => {
                const cleanReason = (reason || '').trim();
                if (!cleanReason) {
                    if (error) {
                        error.textContent = 'Please provide a cancellation reason.';
                        error.hidden = false;
                    }
                    reasonField.focus();
                    return;
                }

                hiddenReason.value = cleanReason;
                confirm.disabled = true;
                form.submit();
            };

            trigger.addEventListener('click', () => {
                clearError();
                reasonField.value = '';
                updateCounter();

                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                    window.setTimeout(() => reasonField.focus(), 0);
                    return;
                }

                const fallbackReason = window.prompt('Enter the cancellation reason:');
                if (fallbackReason !== null) submitCancellation(fallbackReason);
            });

            back?.addEventListener('click', closeDialog);
            confirm.addEventListener('click', () => submitCancellation(reasonField.value));
            reasonField.addEventListener('input', () => {
                clearError();
                updateCounter();
            });

            updateCounter();

            dialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                closeDialog();
            });

            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) closeDialog();
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeBorrowerRequestCancellation, { once: true });
    } else {
        initializeBorrowerRequestCancellation();
    }
})();
</script>
@endonce

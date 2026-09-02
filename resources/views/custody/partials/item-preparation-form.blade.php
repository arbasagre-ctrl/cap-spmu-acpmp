<form
    method="post"
    action="{{ route('custody.prepare', $custody) }}"
    class="form-grid release-preparation-form"
    data-item-preparation-form
>
    @csrf
    @if(!$preparationComplete)
        <p>
            Prepare the approved items for the scheduled pickup, then enter the actual quantity prepared for each item.
            The system compares each entry with the approved quantity. Enter these quantities once; all items must match
            before preparation can be confirmed.
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Approved Qty</th>
                        <th>Actual Prepared</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($custody->lines as $line)
                        <tr>
                            <td>
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->requestItem->unit_snapshot }}</small>
                            </td>
                            <td data-approved-display>{{ $line->approved_quantity + 0 }}</td>
                            <td>
                                <div class="prepared-quantity-stepper">
                                    <button
                                        type="button"
                                        class="prepared-quantity-step"
                                        data-prepared-step="-1"
                                        aria-label="Decrease prepared quantity for {{ $line->requestItem->description_snapshot }}"
                                    >&minus;</button>

                                    <input
                                        type="number"
                                        step="1"
                                        min="0"
                                        inputmode="numeric"
                                        class="actual-prepared-quantity"
                                        name="quantities[{{ $line->id }}]"
                                        value="{{ old('quantities.'.$line->id) }}"
                                        placeholder="Enter actual count"
                                        data-prepared-quantity
                                        data-approved="{{ (float) $line->approved_quantity }}"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="prepared-quantity-step"
                                        data-prepared-step="1"
                                        aria-label="Increase prepared quantity for {{ $line->requestItem->description_snapshot }}"
                                    >+</button>
                                </div>
                            </td>
                            <td>
                                <strong data-preparation-result class="is-unchecked">Not Checked</strong>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="callout info preparation-match-message" data-preparation-message role="status">
            Enter the actual prepared quantity for every item. Confirmation stays disabled until all entries match.
        </div>

        <div class="release-form-actions">
            <button class="button primary ui-pressable release-primary" data-confirm-preparation disabled>
                Confirm Preparation
            </button>
        </div>
    @else
        <div class="empty-state compact">
            <strong>Preparation confirmed.</strong>
            <span>
                The actual prepared quantities were confirmed once against the approved quantities.
                No quantity re-entry is required for the scheduled release.
            </span>
        </div>
    @endif
</form>

<script>
    (() => {
        const form = document.querySelector('[data-item-preparation-form]');
        if (!form) return;

        const inputs = [...form.querySelectorAll('[data-prepared-quantity]')];
        const confirmButton = form.querySelector('[data-confirm-preparation]');
        const message = form.querySelector('[data-preparation-message]');

        if (inputs.length === 0 || !confirmButton) return;

        const epsilon = 0.0005;

        const refreshPreparation = () => {
            let allEntered = true;
            let allMatched = true;

            inputs.forEach((input) => {
                const row = input.closest('tr');
                const result = row?.querySelector('[data-preparation-result]');

                if (!result) return;

                const rawValue = input.value.trim();

                result.classList.remove(
                    'is-unchecked',
                    'is-match',
                    'is-mismatch'
                );

                if (rawValue === '') {
                    allEntered = false;
                    allMatched = false;

                    result.textContent = 'Not Checked';
                    result.classList.add('is-unchecked');

                    return;
                }

                const actual = Number.parseFloat(rawValue);
                const approved = Number.parseFloat(input.dataset.approved || '0');

                const matched =
                    Number.isFinite(actual)
                    && Number.isFinite(approved)
                    && Math.abs(actual - approved) <= epsilon;

                if (matched) {
                    result.textContent = '✓ Match';
                    result.classList.add('is-match');
                } else {
                    result.textContent = 'Mismatch';
                    result.classList.add('is-mismatch');
                    allMatched = false;
                }
            });

            const canConfirm = allEntered && allMatched;
            confirmButton.disabled = !canConfirm;

            if (!message) return;

            message.classList.remove('info', 'warning', 'success');

            if (canConfirm) {
                message.classList.add('success');
                message.textContent =
                    'All prepared quantities match the approved quantities. You may confirm preparation and continue to the physical documents step.';
            } else if (!allEntered) {
                message.classList.add('info');
                message.textContent =
                    'Enter the actual prepared quantity for every item. Confirmation stays disabled until all entries are entered and match the approved quantities.';
            } else {
                message.classList.add('warning');
                message.textContent =
                    'Preparation discrepancy: one or more physical counts do not match the approved quantities. Do not proceed with release. Recheck the physical stock and reconcile the discrepancy before confirming preparation.';
            }
        };

        /*
         * Stepping is bound to `click` only. There is deliberately no
         * pointerdown/mousedown repeat, timer or long-press handler, so a held
         * mouse or touchpad button changes the quantity exactly once.
         */
        const readMinimum = (input) => {
            const min = Number.parseInt(input.min, 10);

            return Number.isFinite(min) ? min : 0;
        };

        const readMaximum = (input) => {
            /*
             * The approved quantity is the existing ceiling: CustodyService
             * only accepts a prepared quantity equal to it. No new limit is
             * introduced here.
             */
            const approved = Number.parseFloat(input.dataset.approved ?? '');

            return Number.isFinite(approved) ? Math.trunc(approved) : null;
        };

        const readCurrent = (input) => {
            const current = Number.parseInt(input.value, 10);

            return Number.isFinite(current) ? current : readMinimum(input);
        };

        const updateStepButtons = () => {
            inputs.forEach((input) => {
                const stepper = input.closest('.prepared-quantity-stepper');

                if (!stepper) return;

                const current = readCurrent(input);
                const min = readMinimum(input);
                const max = readMaximum(input);

                stepper.querySelectorAll('[data-prepared-step]').forEach((button) => {
                    const delta = Number.parseInt(button.dataset.preparedStep, 10);
                    const hadFocus = document.activeElement === button;

                    button.disabled = delta < 0
                        ? current <= min
                        : max !== null && current >= max;

                    /* Keyboard focus follows the field rather than being dropped. */
                    if (button.disabled && hadFocus) {
                        input.focus();
                    }
                });
            });
        };

        const refresh = () => {
            refreshPreparation();
            updateStepButtons();
        };

        const stepQuantity = (input, delta) => {
            const min = readMinimum(input);
            const max = readMaximum(input);
            const next = readCurrent(input) + delta;

            if (next < min) return;
            if (delta > 0 && max !== null && next > max) return;

            input.value = String(next);
            refresh();
        };

        /*
         * A manually typed entry is only normalised to a whole number at or
         * above the minimum. An over-count is left as typed so the existing
         * mismatch warning still fires, and the server remains authoritative.
         */
        const normalizeQuantity = (input) => {
            const raw = input.value.trim();

            if (raw === '') return;

            const parsed = Number.parseFloat(raw);

            if (!Number.isFinite(parsed)) {
                input.value = '';
                return;
            }

            input.value = String(Math.max(readMinimum(input), Math.trunc(parsed)));
        };

        inputs.forEach((input) => {
            input.addEventListener('input', refresh);

            input.addEventListener('change', () => {
                normalizeQuantity(input);
                refresh();
            });

            input
                .closest('.prepared-quantity-stepper')
                ?.querySelectorAll('[data-prepared-step]')
                .forEach((button) => {
                    button.addEventListener('click', () => {
                        stepQuantity(
                            input,
                            Number.parseInt(button.dataset.preparedStep, 10)
                        );
                    });
                });
        });

        refresh();
    })();
    </script>

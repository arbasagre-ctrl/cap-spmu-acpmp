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
                                <input
                                    type="number"
                                    step="1"
                                    min="0"
                                    inputmode="numeric"
                                    name="quantities[{{ $line->id }}]"
                                    value="{{ old('quantities.'.$line->id) }}"
                                    placeholder="Enter actual count"
                                    data-prepared-quantity
                                    data-approved="{{ (float) $line->approved_quantity }}"
                                    required
                                >
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

        inputs.forEach((input) => {
            input.addEventListener('input', refreshPreparation);
            input.addEventListener('change', refreshPreparation);
        });

        refreshPreparation();
    })();
    </script>

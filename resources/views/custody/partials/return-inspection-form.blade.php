@php
    /*
     * Linen condition is verified from the accomplished Laundry Form signed by
     * Laundry Personnel; non-linen stays a direct Action Officer inspection.
     * The same rule is enforced server-side in CustodyService::receiveReturn.
     */
    $linenReturnLines = $eligibleReturnLines->filter(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $returnLaundryJob = $laundryJob ?? $custody->laundryJob;

    $laundryFormVerified = (bool) (
        $returnLaundryJob?->latest_evidence_submission_id
        && $returnLaundryJob?->form_verified_at
    );

    $laundryFormMissing = $linenReturnLines->isNotEmpty() && ! $laundryFormVerified;
@endphp

@if($eligibleReturnLines->isNotEmpty())
    <form
        method="post"
        action="{{ route('custody.return', $custody) }}"
        enctype="multipart/form-data"
        class="card form-grid return-inspection-card"
        id="full-return-accounting-form"
        @if($laundryFormMissing) data-laundry-form-missing="1" @endif
    >
        @csrf

        <div class="card-header return-inspection-header">
            <div>
                <p class="eyebrow">Physical return inspection</p>
                <h2>Account returned quantities</h2>
            </div>

            <span class="status-badge status-info return-outstanding-badge">
                {{ $eligibleReturnLines->count() }} {{ $eligibleReturnLines->count() === 1 ? 'item type' : 'item types' }} outstanding
            </span>
        </div>

        @if($linenReturnLines->isNotEmpty())
            @if($laundryFormMissing)
                <div class="callout danger return-linen-note">
                    <x-icon name="warning" size="22" />
                    <div><strong>Completed Laundry Form required</strong>
                    <p>Upload the accomplished Laundry Form before encoding the linen return.</p></div>
                </div>
            @else
                <div class="callout info return-linen-note">
                    <x-icon name="information" size="22" />
                    <div><strong>Encode from Laundry Form</strong>
                    <p>For linen, record the quantity and condition exactly as written by Laundry Personnel. Non-linen remains an Action Officer physical inspection.</p></div>
                </div>
            @endif
        @endif

        <div class="table-wrap return-inspection-scroll">
            <table class="return-inspection-table">
                <colgroup>
                    <col class="return-item-column">
                    <col span="6" class="return-condition-column">
                    <col class="return-total-column">
                </colgroup>
                <thead>
                    <tr>
                        <th scope="col">Item / Outstanding</th>
                        <th scope="col">Fine / Good</th>
                        <th scope="col">Damaged</th>
                        <th scope="col">Destroyed</th>
                        <th scope="col">Missing</th>
                        <th scope="col">Lost</th>
                        <th scope="col">Stolen</th>
                        <th scope="col">Accounted</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($eligibleReturnLines as $line)
                        @php
                            $outstanding = max(
                                0,
                                (float) $line->actual_released_quantity
                                    - (float) $line->returned_quantity
                            );

                            $oldBreakdown = old(
                                'accounting.'.$line->id,
                                []
                            );

                            $oldNonFine = collect([
                                'DAMAGED',
                                'DESTROYED',
                                'MISSING',
                                'LOST',
                                'STOLEN',
                            ])->sum(
                                fn ($code) =>
                                    (float) ($oldBreakdown[$code] ?? 0)
                            );

                            $oldStolen =
                                (float) ($oldBreakdown['STOLEN'] ?? 0);
                        @endphp

                        <tr
                            class="return-accounting-row"
                            data-outstanding="{{ $outstanding }}"
                        >
                            <td class="return-item-cell">
                                <strong>
                                    {{ $line->requestItem->description_snapshot }}
                                </strong>
                                <small>
                                    {{ (bool) $line->requestItem?->inventoryItem?->laundry_required
                                        ? 'Linen · condition from Laundry Form'
                                        : 'Non-linen · inspected by Action Officer' }}
                                    · {{ $line->requestItem->unit_snapshot }}
                                </small>
                                <small>Outstanding: {{ $outstanding + 0 }}</small>
                            </td>

                            @foreach([
                                'FINE',
                                'DAMAGED',
                                'DESTROYED',
                                'MISSING',
                                'LOST',
                                'STOLEN',
                            ] as $conditionCode)
                                <td>
                                    <input
                                        type="number"
                                        step="1"
                                        min="0"
                                        max="{{ $outstanding }}"
                                        inputmode="numeric"
                                        class="return-accounting-input"
                                        data-condition="{{ $conditionCode }}"
                                        name="accounting[{{ $line->id }}][{{ $conditionCode }}]"
                                        value="{{ old('accounting.'.$line->id.'.'.$conditionCode, 0) }}"
                                        aria-label="{{ str($conditionCode)->replace('_', ' ')->title() }} quantity for {{ $line->requestItem->description_snapshot }}"
                                    >
                                </td>
                            @endforeach

                            <td>
                                <strong class="return-accounted-total">
                                    0 / {{ $outstanding + 0 }}
                                </strong>
                                <small class="return-accounted-state">
                                    0% accounted
                                </small>
                            </td>
                        </tr>

                        <tr
                            class="return-issue-details"
                            data-return-issue-details
                            @if($oldNonFine <= 0) hidden @endif
                        >
                            <td colspan="8">
                                <div class="return-issue-details__grid">
                                    <label data-evidence-wrap>
                                        Evidence for non-good quantity
                                        <input
                                            type="file"
                                            class="return-evidence-input"
                                            name="evidence_files[{{ $line->id }}]"
                                            accept="application/pdf,image/png,image/jpeg,image/webp"
                                        >
                                        <small>
                                            Required only when Damaged,
                                            Destroyed, Missing, Lost, or
                                            Stolen is greater than zero.
                                        </small>
                                    </label>

                                    <label
                                        data-police-wrap
                                        @if($oldStolen <= 0) hidden @endif
                                    >
                                        Police / blotter reference
                                        <input
                                            class="return-police-input"
                                            name="police_blotter_references[{{ $line->id }}]"
                                            value="{{ old('police_blotter_references.'.$line->id) }}"
                                        >
                                        <small>
                                            Required only when Stolen is
                                            greater than zero.
                                        </small>
                                    </label>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="return-action-area">
            <div
                class="callout warning return-accounting-message"
                id="return-accounting-message"
                role="status"
            >
                <x-icon name="warning" size="21" data-return-accounting-warning />
                <x-icon name="success" size="21" data-return-accounting-success hidden />
                <span data-return-accounting-copy>@if($laundryFormMissing)Completed Laundry Form required before the linen return can be finalized.@else For each selected item, Fine + Damaged + Destroyed + Missing + Lost + Stolen must equal its full outstanding quantity.@endif</span>
            </div>

            <div class="return-action-footer">
                <label>
                    Inspection Remarks
                    <span class="return-remarks-input">
                        <textarea
                            name="remarks"
                            rows="5"
                            maxlength="2000"
                            aria-describedby="return-remarks-counter"
                            placeholder="Optional inspection note (e.g., 2 table cloths stained, minor marks observed)"
                        >{{ old('remarks') }}</textarea>
                        <span class="return-remarks-counter" id="return-remarks-counter"><span data-return-remarks-count>{{ mb_strlen((string) old('remarks', '')) }}</span> / 2000</span>
                    </span>
                </label>

                <button
                    class="button primary ui-pressable link-button"
                    id="record-return-button"
                    type="submit"
                    disabled
                >
                    Record Return Inspection
                </button>
            </div>
        </div>
    </form>
@endif

@if($eligibleReturnLines->isEmpty())
    <article class="card return-empty-state">
        <div class="empty-state">
            <strong>
                No return item requires encoding.
            </strong>
            <span>
                Review the Return Status panel for the next action.
            </span>
        </div>
    </article>
@endif

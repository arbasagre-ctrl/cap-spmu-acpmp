@php
    /*
     * Mixed return rule:
     * - Non-linen is physically received and inspected directly by the Action Officer.
     * - Linen is encoded only from the accomplished Laundry Form.
     * - A pending Laundry Form must NEVER block a valid non-linen return inspection.
     * - No partial branch return: every outstanding item type in a selected return channel is accounted together.
     */
    $linenReturnLines = $eligibleReturnLines->filter(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $nonLinenReturnLines = $eligibleReturnLines->reject(
        fn ($line) => (bool) $line->requestItem?->inventoryItem?->laundry_required
    );

    $orderedReturnLines = $nonLinenReturnLines->concat($linenReturnLines);

    $returnLaundryJob = $laundryJob ?? $custody->laundryJob;

    $laundryFormVerified = (bool) (
        $returnLaundryJob?->latest_evidence_submission_id
        && $returnLaundryJob?->form_verified_at
    );

    $laundryFormMissing = $linenReturnLines->isNotEmpty() && ! $laundryFormVerified;
    $mixedReturn = $nonLinenReturnLines->isNotEmpty() && $linenReturnLines->isNotEmpty();
    $nonLinenOnly = $nonLinenReturnLines->isNotEmpty() && $linenReturnLines->isEmpty();
    $linenOnly = $linenReturnLines->isNotEmpty() && $nonLinenReturnLines->isEmpty();

    $shownNonLinenHeading = false;
    $shownLinenHeading = false;
@endphp

@if($eligibleReturnLines->isNotEmpty())
    <form
        method="post"
        action="{{ route('custody.return', $custody) }}"
        enctype="multipart/form-data"
        class="card form-grid return-inspection-card"
        id="full-return-accounting-form"
        @if($laundryFormMissing) data-laundry-form-missing="1" @endif
        @if($mixedReturn) data-mixed-return="1" @endif
        @if($nonLinenOnly) data-non-linen-only="1" @endif
        @if($linenOnly) data-linen-only="1" @endif
    >
        @csrf

        <div class="card-header return-inspection-header">
            <div>
                <p class="eyebrow">Physical return inspection</p>
                <h2>Record returned quantities</h2>
            </div>

            <span class="status-badge status-info return-outstanding-badge">
                {{ $eligibleReturnLines->count() }}
                {{ $eligibleReturnLines->count() === 1 ? 'item type' : 'item types' }}
                outstanding
            </span>
        </div>

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
                        <th scope="col">Good</th>
                        <th scope="col">Damaged</th>
                        <th scope="col">Destroyed</th>
                        <th scope="col">Missing</th>
                        <th scope="col">Lost</th>
                        <th scope="col">Stolen</th>
                        <th scope="col">Accounted</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($orderedReturnLines as $line)
                        @php
                            $isLinenLine = (bool) $line->requestItem?->inventoryItem?->laundry_required;
                            $linenLinePending = $isLinenLine && $laundryFormMissing;

                            $outstanding = max(
                                0,
                                (float) $line->actual_released_quantity - (float) $line->returned_quantity
                            );

                            $oldBreakdown = old('accounting.'.$line->id, []);

                            $oldNonFine = collect([
                                'DAMAGED',
                                'DESTROYED',
                                'MISSING',
                                'LOST',
                                'STOLEN',
                            ])->sum(
                                fn ($code) => (float) ($oldBreakdown[$code] ?? 0)
                            );

                            $oldStolen = (float) ($oldBreakdown['STOLEN'] ?? 0);
                        @endphp

                        @if($mixedReturn && !$isLinenLine && !$shownNonLinenHeading)
                            @php($shownNonLinenHeading = true)
                            <tr class="return-inspection-section-row return-inspection-section-row--non-linen">
                                <td colspan="8">
                                    <div class="return-inspection-section-heading">
                                        <div>
                                            <strong>Non-linen items</strong>
                                            <small>Record the full quantity for each item returned to SPMU.</small>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        @if($isLinenLine && !$shownLinenHeading)
                            @php($shownLinenHeading = true)
                            <tr class="return-inspection-section-row return-inspection-section-row--linen">
                                <td colspan="8">
                                    <div class="return-inspection-section-heading">
                                        <div>
                                            <strong>{{ $mixedReturn ? 'Linen items' : 'Linen return' }}</strong>
                                            <small>
                                                @if($laundryFormMissing)
                                                    Record the accomplished Laundry Form before entering linen quantities.
                                                @else
                                                    Encode linen quantities from the accomplished Laundry Form.
                                                @endif
                                            </small>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif

                        <tr
                            class="return-accounting-row {{ $linenLinePending ? 'is-locked' : '' }}"
                            data-outstanding="{{ $outstanding }}"
                            data-return-kind="{{ $isLinenLine ? 'linen' : 'non-linen' }}"
                            @if($linenLinePending) data-linen-pending="1" @endif
                        >
                            <td class="return-item-cell">
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $isLinenLine ? 'Linen' : 'Non-linen' }} · {{ $line->requestItem->unit_snapshot }}</small>
                                <small>Outstanding: {{ $outstanding + 0 }}</small>
                            </td>

                            @foreach(['FINE', 'DAMAGED', 'DESTROYED', 'MISSING', 'LOST', 'STOLEN'] as $conditionCode)
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
                                        @disabled($linenLinePending)
                                    >
                                </td>
                            @endforeach

                            <td>
                                @if($linenLinePending)
                                    <span class="return-accounted-state" title="Available after the accomplished Laundry Form is recorded">—</span>
                                @else
                                    <strong class="return-accounted-total">0 / {{ $outstanding + 0 }}</strong>
                                    <small class="return-accounted-state">Not yet accounted</small>
                                @endif
                            </td>
                        </tr>

                        <tr class="return-issue-details" data-return-issue-details @if($linenLinePending || $oldNonFine <= 0) hidden @endif>
                            <td colspan="8">
                                <div class="return-issue-details__grid">
                                    <label data-evidence-wrap>
                                        Supporting evidence
                                        <input
                                            type="file"
                                            class="return-evidence-input"
                                            name="evidence_files[{{ $line->id }}]"
                                            accept="application/pdf,image/png,image/jpeg,image/webp"
                                        >
                                        <small>
                                            @if($isLinenLine)
                                                Optional for linen. The accomplished Laundry Form remains the main condition record.
                                            @else
                                                Required when Damaged, Destroyed, Missing, Lost, or Stolen is greater than zero.
                                            @endif
                                        </small>
                                    </label>

                                    <label data-police-wrap @if($oldStolen <= 0) hidden @endif>
                                        Police / blotter reference
                                        <input class="return-police-input" name="police_blotter_references[{{ $line->id }}]" value="{{ old('police_blotter_references.'.$line->id) }}">
                                        <small>Required only when Stolen is greater than zero.</small>
                                    </label>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(!($linenOnly && $laundryFormMissing))
            <div class="return-action-area" id="return-action-area">
                <div class="return-action-footer">
                    <label>
                        Inspection Note (optional)
                        <span class="return-remarks-input">
                            <textarea name="remarks" rows="2" maxlength="2000" placeholder="Add a note about this inspection">{{ old('remarks') }}</textarea>
                        </span>
                    </label>

                    <button class="button primary ui-pressable link-button" id="record-return-button" type="submit" disabled>
                        @if($linenOnly)
                            Record Linen Return Findings
                        @elseif($mixedReturn && $laundryFormMissing)
                            Record Non-Linen Return Inspection
                        @else
                            Record Return Inspection
                        @endif
                    </button>
                </div>
            </div>
        @endif
    </form>
@endif

@if($eligibleReturnLines->isEmpty())
    <article class="card return-empty-state">
        <div class="empty-state">
            <strong>Physical return completed.</strong>
            <span>All issued property has been accounted for. See Return History below for the recorded inspection.</span>
        </div>
    </article>
@endif

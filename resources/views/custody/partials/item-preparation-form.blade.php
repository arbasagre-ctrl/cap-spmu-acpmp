@php
    $preparationIssueRecords = collect($preparationIssues ?? []);
    $openPreparationIssues = $preparationIssueRecords
        ->where('is_resolved', false)
        ->values();
    $reportPreparationPanelOpen = $errors->hasAny([
        'custody_line_id',
        'issue_type',
        'observed_usable_quantity',
        'condition_observed',
        'details',
        'preparation_issue',
    ]);
@endphp

<div class="release-preparation-form" data-item-preparation-form>
    @if(!$preparationComplete)
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty to Prepare</th>
                        <th>Preparation Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($custody->lines as $line)
                        @php
                            $openLineIssue = $openPreparationIssues->firstWhere('custody_line_id', $line->id);
                            $latestLineIssue = $preparationIssueRecords->firstWhere('custody_line_id', $line->id);
                            $lineStatus = $openLineIssue
                                ? 'Inventory Review Required'
                                : ($latestLineIssue && $latestLineIssue['is_resolved']
                                    ? 'Pending Recheck'
                                    : 'Pending Preparation');
                            $lineTone = $openLineIssue
                                ? 'is-issue'
                                : ($latestLineIssue && $latestLineIssue['is_resolved']
                                    ? 'is-recheck'
                                    : 'is-pending');
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $line->requestItem->description_snapshot }}</strong>
                                <small>{{ $line->requestItem->unit_snapshot }}</small>
                            </td>
                            <td>{{ $line->approved_quantity + 0 }}</td>
                            <td>
                                <span class="release-preparation-status {{ $lineTone }}">
                                    {{ $lineStatus }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($preparationIssueRecords->isNotEmpty())
            <div class="release-preparation-issue-list" aria-label="Inventory discrepancy history">
                @foreach($preparationIssueRecords as $issue)
                    <div class="release-preparation-issue-row {{ $issue['is_resolved'] ? 'is-resolved' : 'is-open' }}">
                        <div>
                            <strong>{{ $issue['item_name'] }}</strong>
                            <span>{{ $issue['issue_label'] }}</span>
                            @if($issue['observed_usable_quantity'] !== null)
                                <small>
                                    Physically ready: {{ $issue['observed_usable_quantity'] + 0 }}
                                    of {{ $issue['approved_quantity'] + 0 }} {{ $issue['unit'] }}
                                </small>
                            @endif
                            @if(!empty($issue['condition_observed']))
                                <small>{{ $issue['condition_observed'] }}</small>
                            @endif
                            @if(!empty($issue['details']))
                                <small>{{ $issue['details'] }}</small>
                            @endif
                        </div>
                        <span class="release-preparation-status {{ $issue['is_resolved'] ? 'is-resolved' : 'is-issue' }}">
                            {{ $issue['is_resolved'] ? 'Inventory Reviewed — Recheck Item' : 'Inventory Review Required' }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="release-preparation-actions">
            <div class="release-preparation-action-row">
                <button
                    class="button release-outline ui-pressable release-preparation-report-toggle"
                    type="button"
                    data-release-panel-toggle
                    aria-controls="release-preparation-report-panel"
                    aria-expanded="{{ $reportPreparationPanelOpen ? 'true' : 'false' }}"
                    aria-label="Show or hide inventory discrepancy report"
                >
                    <span>Report Inventory Discrepancy</span>
                    <x-icon name="chevron-down" size="16" />
                </button>

                <form method="post" action="{{ route('custody.prepare', $custody) }}">
                    @csrf
                    <button
                        class="button primary ui-pressable release-primary"
                        type="submit"
                        @disabled($openPreparationIssues->isNotEmpty())
                    >
                        Confirm Items Prepared
                    </button>
                </form>
            </div>

            <div
                class="release-preparation-report-panel"
                id="release-preparation-report-panel"
                @if(!$reportPreparationPanelOpen) hidden @endif
            >
                <form
                    method="post"
                    action="{{ route('custody.report-preparation-issue', $custody) }}"
                    class="form-grid release-preparation-report-form"
                >
                    @csrf

                    <label>
                        Affected item
                        <select name="custody_line_id" required>
                            <option value="">Select item</option>
                            @foreach($custody->lines as $line)
                                <option value="{{ $line->id }}" @selected((string) old('custody_line_id') === (string) $line->id)>
                                    {{ $line->requestItem->description_snapshot }} — {{ $line->approved_quantity + 0 }} {{ $line->requestItem->unit_snapshot }}
                                </option>
                            @endforeach
                        </select>
                        @error('custody_line_id')<small class="field-error">{{ $message }}</small>@enderror
                    </label>

                    <label>
                        Discrepancy
                        <select name="issue_type" required data-preparation-issue-type>
                            <option value="">Select issue</option>
                            <option value="ITEM_NOT_READY" @selected(old('issue_type') === 'ITEM_NOT_READY')>Item not found / not ready</option>
                            <option value="QUANTITY_AVAILABILITY" @selected(old('issue_type') === 'QUANTITY_AVAILABILITY')>Physical quantity is short</option>
                            <option value="PHYSICAL_CONDITION" @selected(old('issue_type') === 'PHYSICAL_CONDITION')>Physical condition issue</option>
                            <option value="OTHER" @selected(old('issue_type') === 'OTHER')>Other inventory discrepancy</option>
                        </select>
                        @error('issue_type')<small class="field-error">{{ $message }}</small>@enderror
                    </label>

                    <label data-preparation-quantity-field hidden>
                        Physically ready quantity
                        <input
                            type="number"
                            name="observed_usable_quantity"
                            min="0"
                            step="0.001"
                            value="{{ old('observed_usable_quantity') }}"
                            placeholder="Enter quantity"
                            disabled
                        >
                        @error('observed_usable_quantity')<small class="field-error">{{ $message }}</small>@enderror
                    </label>

                    <label data-preparation-condition-field hidden>
                        Condition observed
                        <input
                            type="text"
                            name="condition_observed"
                            maxlength="500"
                            value="{{ old('condition_observed') }}"
                            placeholder="Describe the physical condition"
                            disabled
                        >
                        @error('condition_observed')<small class="field-error">{{ $message }}</small>@enderror
                    </label>

                    <label data-preparation-details-field hidden>
                        <span data-preparation-details-label>Remarks (Optional)</span>
                        <textarea
                            name="details"
                            maxlength="1000"
                            placeholder="Add a short note only if needed."
                            disabled
                            data-preparation-details-input
                        >{{ old('details') }}</textarea>
                        @error('details')<small class="field-error">{{ $message }}</small>@enderror
                        @error('preparation_issue')<small class="field-error">{{ $message }}</small>@enderror
                    </label>

                    <div class="release-form-actions">
                        <button class="button primary ui-pressable release-primary" type="submit">
                            Submit Discrepancy Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @else
        <div class="empty-state compact">
            <strong>Items prepared.</strong>
            <span>
                Physical readiness was confirmed for every approved item. No quantity re-entry is required.
            </span>
        </div>
    @endif
</div>

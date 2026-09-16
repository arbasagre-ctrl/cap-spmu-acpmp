@php
    $balance = $balances[$item->id] ?? [];

    /*
     * The operational list shows PHYSICAL availability, matching the
     * Inventory Status Report. Reserved stock is still physically in SPMU and is shown separately
     * from currently available units.
     */
    $available = (float) (
        $balance['current_available']
        ?? $balance['borrower_available']
        ?? $balance['available']
        ?? 0
    );

    $reserved = (float) ($balance['reserved'] ?? $balance['allocated'] ?? 0);
    $borrowed = (float) ($balance['borrowed'] ?? 0);
    $laundry = (float) ($balance['laundry'] ?? 0);
    $incident = (float) ($balance['incident'] ?? 0);
    $totalStock = (float) $item->total_quantity;
    $unavailable = max(0, $totalStock - $available - $reserved - $borrowed);

    $damagedMaintenance = min($totalStock, (float) ($balance['damaged_maintenance'] ?? 0));
    $lost = min($totalStock, (float) ($balance['lost'] ?? 0));
    $stolen = min($totalStock, (float) ($balance['stolen'] ?? 0));
    $destroyed = min($totalStock, (float) ($balance['destroyed'] ?? 0));
    $condemned = min($totalStock, (float) ($balance['condemned'] ?? 0));
    $knownNonGood = min($totalStock, $damagedMaintenance + $lost + $stolen + $destroyed + $condemned);
    $good = max(0, $totalStock - $knownNonGood);

    $itemCode = 'INV-'.str_pad((string) $item->id, 4, '0', STR_PAD_LEFT);
    $categoryName = $item->category?->category_name ?: 'Uncategorized';
    $unitName = $item->unit?->unit_name ?: '';

    $searchText = strtolower(
        $itemCode.' '.
        $item->unique_description.' '.
        ($item->specification ?? '').' '.
        $categoryName.' '.
        $unitName
    );
@endphp

<tr
    data-spmu-inventory-row
    data-search="{{ $searchText }}"
    data-category="{{ strtolower($categoryName) }}"
>
    <td>
        <span class="spmu-inventory-id">{{ $itemCode }}</span>
    </td>

    <td class="spmu-inventory-item">
        <strong>{{ $item->unique_description }}</strong>
        <small>{{ $categoryName }}{{ $unitName ? ' · '.$unitName : '' }}</small>
    </td>

    <td class="is-numeric"><span class="spmu-inventory-count">{{ $totalStock + 0 }}</span></td>

    <td class="is-numeric"><span class="spmu-inventory-count">{{ $available + 0 }}</span></td>

    <td class="is-numeric"><span class="spmu-inventory-count">{{ $reserved + 0 }}</span></td>

    <td class="is-numeric"><span class="spmu-inventory-count">{{ $borrowed + 0 }}</span></td>

    <td>
        <span class="spmu-inventory-states">
            <strong>{{ $unavailable + 0 }} unavailable</strong>
            @if($laundry > 0)
                <span class="has-open">{{ $laundry + 0 }} in laundry</span>
            @endif
            @if($incident > 0)
                <span class="has-open">{{ $incident + 0 }} condition / incident hold</span>
            @endif
        </span>
    </td>

    <td data-condition="{{ $item->condition_code }}">
        <span class="spmu-condition-summary">
            <strong>{{ $good + 0 }} good / serviceable</strong>
            @if($damagedMaintenance > 0)<small>{{ $damagedMaintenance + 0 }} damaged / under repair</small>@endif
            @if($lost > 0)<small>{{ $lost + 0 }} lost</small>@endif
            @if($stolen > 0)<small>{{ $stolen + 0 }} stolen</small>@endif
            @if($destroyed > 0)<small>{{ $destroyed + 0 }} destroyed</small>@endif
            @if($condemned > 0)<small>{{ $condemned + 0 }} condemned</small>@endif
        </span>
    </td>

    <td class="spmu-inventory-use">
        {{ $item->off_campus_allowed ? 'Off-campus allowed' : 'On-campus only' }}

        @if($item->laundry_required)
            <small>Laundry required</small>
        @endif
    </td>

    <td>
        <span class="spmu-inventory-actions">
            <a
                class="table-action ui-pressable"
                href="{{ route('inventory.show', $item) }}"
                aria-label="View {{ $item->unique_description }}"
            >
                <x-icon name="eye" size="16" />
                View
            </a>

            @if($isInventoryAdmin)
                <a
                    class="table-action ui-pressable"
                    href="{{ route('inventory.edit', $item) }}"
                    aria-label="Edit {{ $item->unique_description }}"
                >
                    <x-icon name="edit" size="16" />
                    Edit
                </a>
            @endif
        </span>
    </td>
</tr>

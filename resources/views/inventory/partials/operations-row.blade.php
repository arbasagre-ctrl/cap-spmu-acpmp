@php
    $balance = $balances[$item->id] ?? [];

    $available = (float) (
        $balance['current_available']
        ?? $balance['borrower_available']
        ?? $balance['available']
        ?? 0
    );

    $allocated = (float) ($balance['allocated'] ?? $balance['reserved'] ?? 0);
    $borrowed = (float) ($balance['borrowed'] ?? 0);
    $laundry = (float) ($balance['laundry'] ?? 0);
    $incident = (float) ($balance['incident'] ?? 0);
    $totalStock = (float) $item->total_quantity;

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

    <td class="is-numeric"><span class="spmu-inventory-count">{{ $allocated + 0 }}</span></td>

    <td class="is-numeric"><span class="spmu-inventory-count">{{ $borrowed + 0 }}</span></td>

    <td>
        <span class="spmu-inventory-states">
            <span class="{{ $laundry > 0 ? 'has-open' : '' }}">{{ $laundry + 0 }} laundry</span>
            <span class="{{ $incident > 0 ? 'has-open' : '' }}">{{ $incident + 0 }} issue</span>
        </span>
    </td>

    <td data-condition="{{ $item->condition_code }}">
        <x-status-badge :status="$item->condition_code" />
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

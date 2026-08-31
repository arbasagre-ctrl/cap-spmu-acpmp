@extends('layouts.app', ['title' => $item->exists ? 'Edit Inventory Item' : 'Add Inventory Item'])

@section('content')
@include('inventory.partials.form-styles')

<div class="inventory-form-page">
    <section class="page-heading inventory-form-heading">
        <div>
            <p class="eyebrow">SPMU inventory administration</p>
            <h1>{{ $item->exists ? 'Edit inventory item' : 'Add inventory item' }}</h1>
        </div>

        <a class="inventory-form-back" href="{{ route('inventory.index') }}">
            <x-icon name="chevron-right" size="16" />
            Back to inventory
        </a>
    </section>

    <section class="content-area">
        <form
            method="post"
            action="{{ $item->exists ? route('inventory.update', $item) : route('inventory.store') }}"
            class="inventory-form-card"
        >
            @csrf
            @if($item->exists)
                @method('PUT')
            @endif

            <div class="inventory-form-columns">
                <label>
                    Category
                    <select name="category_id" required>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}" @selected(old('category_id', $item->category_id) == $category->id)>
                                {{ $category->category_name }}
                            </option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Unit
                    <select name="unit_id" required>
                        @foreach($units as $unit)
                            <option value="{{ $unit->id }}" @selected(old('unit_id', $item->unit_id) == $unit->id)>
                                {{ $unit->unit_name }}
                            </option>
                        @endforeach
                    </select>
                </label>
            </div>

            <label>
                Unique description
                <input name="unique_description" value="{{ old('unique_description', $item->unique_description) }}" required>
            </label>

            <label>
                Specification
                <textarea name="specification">{{ old('specification', $item->specification) }}</textarea>
            </label>

            <div class="inventory-form-columns">
                <label>
                    Total quantity
                    <input
                        type="number"
                        step="1"
                        min="0"
                        inputmode="numeric"
                        name="total_quantity"
                        value="{{ old('total_quantity', $item->total_quantity ?? 0) }}"
                        required
                    >
                </label>

                <label>
                    Condition
                    <select name="condition_code">
                        <option value="SERVICEABLE" @selected(old('condition_code', $item->condition_code) === 'SERVICEABLE')>Serviceable</option>
                        <option value="DAMAGED_MAINTENANCE" @selected(old('condition_code', $item->condition_code) === 'DAMAGED_MAINTENANCE')>Damaged / Maintenance</option>
                        <option value="CONDEMNED" @selected(old('condition_code', $item->condition_code) === 'CONDEMNED')>Condemned</option>
                    </select>
                </label>
            </div>

            <fieldset>
                <legend>Operational flags</legend>

                <div class="inventory-form-flags">
                    <label>
                        <input type="checkbox" name="borrowable" value="1" @checked(old('borrowable', $item->exists ? $item->borrowable : true))>
                        Borrowable
                    </label>

                    <label>
                        <input type="checkbox" name="laundry_required" value="1" @checked(old('laundry_required', $item->laundry_required))>
                        Laundry required
                    </label>

                    <label>
                        <input type="checkbox" name="off_campus_allowed" value="1" @checked(old('off_campus_allowed', $item->off_campus_allowed))>
                        Off-campus allowed (Barricade only)
                    </label>

                    <label>
                        <input type="checkbox" name="provisional" value="1" @checked(old('provisional', $item->provisional))>
                        Provisional
                    </label>

                    <label>
                        <input type="checkbox" name="active" value="1" @checked(old('active', $item->exists ? $item->active : true))>
                        Active
                    </label>
                </div>
            </fieldset>

            <label>
                Mandatory change reason
                <textarea name="change_reason" required>{{ old('change_reason') }}</textarea>
            </label>

            <div class="inventory-form-actions">
                <a class="button secondary ui-pressable inventory-form-cancel" href="{{ route('inventory.index') }}">
                    Cancel
                </a>

                <button class="button primary ui-pressable inventory-form-save" type="submit">
                    {{ $item->exists ? 'Save Changes' : 'Add Inventory Item' }}
                </button>
            </div>
        </form>
    </section>
</div>
@endsection

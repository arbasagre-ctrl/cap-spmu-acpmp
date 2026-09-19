@extends('layouts.app', ['title' => $item->exists ? 'Edit Inventory Details' : 'Add Inventory Item'])

@section('content')
@include('inventory.partials.form-styles')

<div class="inventory-form-page">
    <section class="page-heading inventory-form-heading">
        <div>
            <p class="eyebrow">SPMU inventory administration</p>
            <h1>{{ $item->exists ? 'Edit inventory details' : 'Add inventory item' }}</h1>
        </div>

        <a class="inventory-form-back" href="{{ $item->exists ? route('inventory.show', $item) : route('inventory.index') }}">
            <x-icon name="arrow-left" size="16" />
            Back
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

            @if($item->exists)
                <section class="inventory-form-stock-control" aria-label="Physical stock control">
                    <div class="inventory-form-stock-control-head">
                        <div>
                            <span>Physical stock control</span>
                            <strong>Managed from Inventory Overview</strong>
                        </div>
                        <a class="button secondary small ui-pressable" href="{{ route('inventory.show', ['inventory' => $item->id, 'tab' => 'overview']) }}">
                            View Inventory Overview
                            <x-icon name="arrow-right" size="14" />
                        </a>
                    </div>

                    <div class="inventory-form-stock-values">
                        <div>
                            <span>Total Stock</span>
                            <strong>{{ (float) $item->total_quantity + 0 }}</strong>
                        </div>
                        <div>
                            <span>Master Condition</span>
                            <strong>{{ match($item->condition_code) {
                                'SERVICEABLE' => 'Good / Serviceable',
                                'DAMAGED_MAINTENANCE' => 'Damaged / Under Repair',
                                'CONDEMNED' => 'Condemned',
                                default => str($item->condition_code)->replace('_', ' ')->title(),
                            } }}</strong>
                        </div>
                    </div>

                </section>
            @else
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
                        Initial condition
                        <select name="condition_code">
                            <option value="SERVICEABLE" @selected(old('condition_code', $item->condition_code) === 'SERVICEABLE')>Good / Serviceable</option>
                            <option value="DAMAGED_MAINTENANCE" @selected(old('condition_code', $item->condition_code) === 'DAMAGED_MAINTENANCE')>Damaged / Under Repair</option>
                            <option value="CONDEMNED" @selected(old('condition_code', $item->condition_code) === 'CONDEMNED')>Condemned</option>
                        </select>
                    </label>
                </div>
            @endif

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
                    @error('active')<small class="field-error inventory-form-flag-error">{{ $message }}</small>@enderror
                </div>
            </fieldset>

            @if($item->exists)
                <label>
                    Reason for Change
                    <textarea
                        name="change_reason"
                        required
                        placeholder="Briefly state why the inventory details are being updated."
                    >{{ old('change_reason') }}</textarea>
                    @error('change_reason')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            @else
                <label>
                    Initial Stock Source / Reference <small>(Optional)</small>
                    <textarea
                        name="initial_stock_source"
                        placeholder="Example: Delivery Receipt, Purchase Order, property transfer, or opening inventory reference."
                    >{{ old('initial_stock_source') }}</textarea>
                    @error('initial_stock_source')<small class="field-error">{{ $message }}</small>@enderror
                </label>
            @endif

            <div class="inventory-form-actions">
                <a class="button secondary ui-pressable inventory-form-cancel" href="{{ $item->exists ? route('inventory.show', $item) : route('inventory.index') }}">
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

<form
    method="get"
    class="spmu-inventory-card spmu-inventory-availability"
    aria-label="Check availability for a borrowing period"
>
    <label>
        Items needed from
        <input
            type="date"
            name="from"
            value="{{ $from->format('Y-m-d') }}"
        >
    </label>

    <label>
        Expected return date
        <input
            type="date"
            name="to"
            value="{{ $to->format('Y-m-d') }}"
        >
    </label>

    <button class="button primary ui-pressable spmu-inventory-check" type="submit">
        Check Availability
    </button>
</form>

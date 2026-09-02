<form method="post" action="{{ route('custody.release', $custody) }}" class="form-grid release-handover-form" @if(!$preparationComplete || !$pickupWindowOpen) aria-describedby="physical-release-availability" @endif>
    @csrf

    <label class="checkbox">
        <input
            id="physical-signatures-confirmed"
            type="checkbox"
            name="physical_signatures_confirmed"
            value="1"
            required
            autocomplete="off"
            @disabled(!$preparationComplete || !$pickupWindowOpen)
        >
        <span>
            @if($hasOffCampusItem)
                I confirm that the Borrower Slip and approved Gate Pass were validated and the barricade was handed to the borrower.
            @elseif($hasLaundryItem)
                I confirm that Laundry Personnel issued the linen and wet-signed <strong>Issued by</strong> on the printed Laundry Form.
            @else
                I confirm that the Borrower Slip and approved item were validated and the item was physically handed to the borrower.
            @endif
        </span>
    </label>

    @error('signature')
        <p class="form-error">{{ $message }}</p>
    @enderror

    <div class="release-handover-footer">
        <label>
            Release Remarks
            <textarea
                name="remarks"
                placeholder="Optional physical handover note"
                @disabled(!$preparationComplete || !$pickupWindowOpen)
            ></textarea>
        </label>

        <button
            id="confirm-physical-release-button"
            class="button primary ui-pressable release-primary"
            type="submit"
            disabled
        >
            Confirm Physical Release
        </button>
    </div>
</form>

<script>
(() => {
    const confirmation = document.getElementById('physical-signatures-confirmed');
    const releaseButton = document.getElementById('confirm-physical-release-button');

    if (!confirmation || !releaseButton) return;

    const refreshReleaseConfirmation = () => {
        releaseButton.disabled = confirmation.disabled || !confirmation.checked;
    };

    /*
     * Browsers can restore checkbox state when navigating back/forward.
     * Physical Release must always be confirmed deliberately for the
     * current handover.
     */
    const resetReleaseConfirmation = () => {
        confirmation.checked = false;
        refreshReleaseConfirmation();
    };

    confirmation.addEventListener('change', refreshReleaseConfirmation);
    window.addEventListener('pageshow', resetReleaseConfirmation);

    resetReleaseConfirmation();
})();
</script>

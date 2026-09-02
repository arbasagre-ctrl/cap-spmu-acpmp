<form method="post" action="{{ route('custody.release', $custody) }}" class="form-grid release-handover-form" @if(!$preparationComplete || !$pickupWindowOpen) aria-describedby="physical-release-availability" @endif>
    @csrf
    <p class="release-handover-intro">
        Confirm the actual handover of approved items.
    </p>

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
        <span>I confirm that I validated the approved/generated Borrower Slip and applicable Gate Pass, completed the physical preparation, and handed the approved items to the borrower.</span>
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
                 * Operational safety: browsers can restore checkbox state when
                 * navigating back/forward. A physical handover confirmation must
                 * always be made deliberately for the current release action.
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

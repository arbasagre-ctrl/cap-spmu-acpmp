{{-- This is a process guide, not an aggregate status for the cases in this list. --}}
<section class="card laundry-flow-card" aria-labelledby="laundry-flow-title">
    <div class="laundry-flow-body">
        <h2 id="laundry-flow-title">Laundry flow</h2>
        <ol class="laundry-flow-rail" aria-label="Laundry process overview">
            <li class="laundry-flow-step">
                <span class="laundry-flow-marker">
                    <svg width="25" height="25" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5H5v16h14V5h-4" /><rect x="9" y="3" width="6" height="4" rx="1" /><path d="M9 11h6M9 15h6" /></svg>
                    <span class="laundry-flow-number">1</span>
                </span>
                <strong>Return recorded</strong>
            </li>
            <li class="laundry-flow-step">
                <span class="laundry-flow-marker">
                    <svg width="27" height="27" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 17H2V5h12v12H7M14 9h4l4 5v3h-3M14 17h1M18 9v5h4" /><circle cx="5" cy="18" r="2" /><circle cx="17" cy="18" r="2" /></svg>
                    <span class="laundry-flow-number">2</span>
                </span>
                <strong>Linen dropped off</strong>
            </li>
            <li class="laundry-flow-step is-emphasized" aria-describedby="laundry-receipt-guidance">
                <span class="laundry-flow-marker"><x-icon name="users" size="27" /><span class="laundry-flow-number">3</span></span>
                <strong>Received by laundry</strong>
            </li>
            <li class="laundry-flow-step is-internal">
                <span class="laundry-flow-marker">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 3 2.8 6.2L21 12l-6.2 2.8L12 21l-2.8-6.2L3 12l6.2-2.8L12 3Z" /></svg>
                    <span class="laundry-flow-number">4</span>
                </span>
                <strong>Clean &amp; available</strong>
            </li>
        </ol>
        <div class="laundry-flow-annotation-row">
            <p class="laundry-flow-annotation" id="laundry-receipt-guidance">Laundry personnel wet-signs <strong>Received by</strong> when items are accepted at the laundry.</p>
        </div>
    </div>
    <p class="laundry-flow-footer"><x-icon name="information" size="17" /><span>Borrower handoff ends at step 3. Internal laundry processing continues until items are available again.</span></p>
</section>

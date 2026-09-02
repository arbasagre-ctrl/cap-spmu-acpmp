<fieldset class="request-premises-panel" id="request-premises-panel" aria-describedby="request-premises-help">
    <legend>Premises</legend>
    <div class="request-premises-options">
        <label class="request-premises-option">
            <input id="request-on-campus-toggle" type="radio" name="request_premises" value="ON_CAMPUS" @checked(!$requestUsesOffCampus)>
            <span>On-campus</span>
        </label>
        <label class="request-premises-option">
            <input id="request-off-campus-toggle" type="radio" name="request_premises" value="OFF_CAMPUS" @checked($requestUsesOffCampus)>
            <span>Off-campus</span>
        </label>
    </div>
    <p id="request-premises-help">Off-campus is available only for eligible items and automatically requires a Gate Pass after final approval.</p>
</fieldset>

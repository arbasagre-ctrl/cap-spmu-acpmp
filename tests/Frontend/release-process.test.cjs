const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const partial = (name) => read(`resources/views/custody/partials/${name}.blade.php`);
const page = read('resources/views/custody/show.blade.php');
const processLayout = partial('release-process');
const heading = partial('release-heading');
const schedule = partial('pickup-schedule-form');
const preparation = partial('item-preparation-form');
const physical = partial('physical-release-form');
const documents = partial('release-documents');
const interactions = partial('release-process-interactions');
const styles = partial('release-process-styles');
const script = (source) => source.match(/<script>([\s\S]*?)<\/script>/)[1];

function fakeElement(attributes = {}) {
    const listeners = new Map();
    const classes = new Set();
    return {
        dataset: {},
        textContent: '',
        focused: false,
        getAttribute: (name) => attributes[name],
        hasAttribute: (name) => Object.hasOwn(attributes, name),
        setAttribute: (name, value) => { attributes[name] = value; },
        addEventListener: (name, callback) => {
            const callbacks = listeners.get(name) || [];
            callbacks.push(callback);
            listeners.set(name, callbacks);
        },
        dispatch(name) { (listeners.get(name) || []).forEach((callback) => callback()); },
        focus() { this.focused = true; },
        classList: {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
        },
    };
}

test('release layout is scoped to unreleased preparing records in the officer release workspace', () => {
    assert.match(page, /\$useReleaseProcessLayout = \$isSpmuOfficer\s+&& \$showReleaseWorkflow\s+&& \$custody->status === 'PREPARING_RELEASE'\s+&& ! \$custody->released_at;/);
    assert.match(page, /@if\(\$useReleaseProcessLayout\)\s+@include\('custody\.partials\.release-process'\)\s+@else/);
    assert.match(partial('return-inspection-form'), /route\('custody\.return', \$custody\)/);
    assert.match(page, /@if\(\$showReturnWorkflow\)/);
});

test('summary and item table display live record data instead of screenshot samples', () => {
    for (const field of ['custody_no', 'request_no', 'full_name', 'scheduled_release_at', 'pickup_expires_at', '$operationalLabel', '$pickupWindowOpen']) {
        assert.ok(heading.includes(field), `Missing live field: ${field}`);
    }
    for (const field of ['purpose_event', '$scheduleDate', '$returnDate', '$hasOffCampusItem', '$hasLaundryItem', 'approved_quantity', 'actual_released_quantity', 'returned_quantity']) {
        assert.ok(processLayout.includes(field), `Missing live field: ${field}`);
    }
    assert.doesNotMatch(heading + processLayout, /CUS-2026|Borrower Demo|Ruby Foundation/);
});

test('release preparation and physical handover reuse the existing forms and operational document links', () => {
    for (const title of ['Pickup &amp; Issuance Schedule', 'Item Preparation', 'Release Documents']) {
        assert.ok(processLayout.includes(`<h3>${title}</h3>`));
    }
    for (const name of ['pickup-schedule-form', 'item-preparation-form', 'physical-release-form', 'release-documents']) {
        assert.equal((processLayout.match(new RegExp(`@include\\('custody\\.partials\\.${name}'\\)`, 'g')) || []).length, 1);
    }
    assert.match(processLayout, /id="item-preparation"/);
    assert.match(processLayout, /id="release-actions"/);
});

test('pickup schedule panel is a read-only status display fed by the automatic system schedule', () => {
    /*
     * Manual pickup date/time entry was replaced by an automatically
     * system-generated schedule (from the SPMU Operational Calendar); the
     * Action Officer confirms/reschedules it but no longer types a date.
     */
    assert.doesNotMatch(schedule, /type="datetime-local"|name="pickup_at"|name="pickup_expires_at"/);
    assert.match(schedule, /Waiting for automatic schedule activation/);
    assert.match(schedule, /SPMU follow-up required/);
    assert.match(schedule, /Borrower notification/);
    assert.match(schedule, /\{\{ \$scheduleNotificationFailed \? 'Needs attention' : 'Sent' \}\}/);
    assert.match(schedule, /method="post" action="\{\{ route\('custody\.reschedule-pickup', \$custody\) \}\}"/);
    assert.match(schedule, /@csrf/);
    assert.match(schedule, /Set Next Valid Pickup Schedule/);
    assert.match(schedule, /Pickup missed|Pickup schedule needs SPMU follow-up/);
});

/*
 * Manual per-line quantity re-entry (with live stepper +/- buttons and a
 * match/mismatch JS validator) was removed. Preparation is now a single
 * "Confirm Items Prepared" action - the approved quantities are used as-is
 * (see ItemPreparationStep2ContractTest, which asserts the controller no
 * longer accepts a 'quantities' payload) - with a separate discrepancy
 * report flow for the exception case. There is no longer any <script> in
 * this partial to test a stepper/mismatch interaction against.
 */
test('preparation keeps its confirm action, discrepancy report contract and matching hooks', () => {
    assert.match(preparation, /route\('custody\.prepare', \$custody\)/);
    assert.match(preparation, /route\('custody\.report-preparation-issue', \$custody\)/);
    assert.match(preparation, /@csrf/);
    assert.match(preparation, /@if\(!\$preparationComplete\)/);
    for (const fragment of [
        'data-item-preparation-form',
        'data-release-panel-toggle',
        'data-preparation-issue-type',
        'data-preparation-quantity-field',
        'data-preparation-condition-field',
        'data-preparation-details-field',
        '@disabled($openPreparationIssues->isNotEmpty())',
        'Confirm Items Prepared',
        'Report Inventory Discrepancy',
        'Submit Discrepancy Report',
    ]) {
        assert.ok(preparation.includes(fragment), `Missing current contract: ${fragment}`);
    }
    assert.doesNotMatch(preparation, /<script>|data-prepared-quantity|name="quantities\[/);
});

test('physical release retains one required confirmation and optional remarks', () => {
    assert.match(physical, /route\('custody\.release', \$custody\)/);
    assert.match(physical, /@csrf/);
    assert.equal((physical.match(/type="checkbox"/g) || []).length, 1);
    assert.match(physical, /id="physical-signatures-confirmed"\s+type="checkbox"\s+name="physical_signatures_confirmed"\s+value="1"\s+required\s+autocomplete="off"/);
    assert.match(physical, /@error\('signature'\)/);
    const remarks = physical.match(/<textarea\b[^>]*>/)[0];
    assert.match(remarks, /name="remarks"/);
    assert.doesNotMatch(remarks, /required/);
    assert.match(physical, /id="confirm-physical-release-button"[\s\S]*?type="submit"\s+disabled/);
});

test('physical handover confirmation is deliberate and resets on page restore', () => {
    const checkbox = fakeElement();
    checkbox.checked = true;
    const button = { disabled: false };
    const window = fakeElement();
    vm.runInNewContext(script(physical), {
        document: { getElementById: (id) => id === 'physical-signatures-confirmed' ? checkbox : button },
        window,
    });
    assert.equal(checkbox.checked, false);
    assert.equal(button.disabled, true);
    checkbox.checked = true;
    checkbox.dispatch('change');
    assert.equal(button.disabled, false);
    checkbox.checked = false;
    checkbox.dispatch('change');
    assert.equal(button.disabled, true);
    checkbox.disabled = true;
    checkbox.checked = true;
    checkbox.dispatch('change');
    assert.equal(button.disabled, true);
    checkbox.disabled = false;
    checkbox.dispatch('change');
    assert.equal(button.disabled, false);
    checkbox.checked = true;
    window.dispatch('pageshow');
    assert.equal(checkbox.checked, false);
    assert.equal(button.disabled, true);
});

test('physical release shows timing notices and disables handover inputs until eligible', () => {
    const physicalStep = processLayout.slice(processLayout.indexOf('release-physical-heading'));
    assert.match(physicalStep, /@if\(\$preparationComplete && \$hasPickupSchedule\)\s+@if\(\$pickupWindowUpcoming\)/);
    assert.match(physicalStep, /@elseif\(\$pickupWindowPassed\)/);
    assert.match(physicalStep, /@endif\s+@include\('custody\.partials\.physical-release-form'\)/);
    assert.equal((physical.match(/@disabled\(!\$preparationComplete \|\| !\$pickupWindowOpen\)/g) || []).length, 2);
    assert.match(physicalStep, /Do not record a late physical release/);
});

test('document links preserve current-document filtering and Gate Pass preview/final distinction', () => {
    assert.match(documents, /whereNotIn\('status', \['SUPERSEDED', 'INVALIDATED', 'EXPIRED'\]\)/);
    assert.match(documents, /route\('documents\.preview', \$document\)/);
    assert.doesNotMatch(documents, /route\('documents\.download', \$document\)/);
    assert.match(documents, /\['READY_FOR_PRINTING', 'VERIFIED'\]/);
    assert.match(documents, /APPROVED GATE PASS — VALIDATE PRESENTED COPY/);
    assert.match(documents, /INVALID — DO NOT RELEASE/);
    assert.match(documents, /Borrower Copy \/ Reference/);
});

test('edit, toggle and cancel update the schedule panel without submitting the form', () => {
    const edit = fakeElement({ 'data-release-schedule-edit': '', 'aria-controls': 'release-schedule-editor', 'aria-expanded': 'false' });
    const toggle = fakeElement({ 'data-release-panel-toggle': '', 'aria-controls': 'release-schedule-editor', 'aria-expanded': 'false' });
    const preparationToggle = fakeElement({ 'data-release-panel-toggle': '', 'aria-controls': 'release-preparation-panel', 'aria-expanded': 'false' });
    const cancel = fakeElement();
    const input = fakeElement();
    const schedulePanel = { hidden: true, querySelector: () => input };
    const preparationPanel = { hidden: true };
    const panels = { 'release-schedule-editor': schedulePanel, 'release-preparation-panel': preparationPanel };
    let resets = 0;
    const form = { reset: () => { resets += 1; } };
    const workspace = {
        dataset: {},
        querySelectorAll: () => [edit, toggle, preparationToggle],
        querySelector: (selector) => ({
            '[data-release-schedule-cancel]': cancel,
            '#release-schedule-editor form': form,
            '[data-release-schedule-edit]': edit,
        })[selector],
        contains: (node) => Object.values(panels).includes(node),
    };
    const context = { document: { querySelector: () => workspace, getElementById: (id) => panels[id] } };
    vm.runInNewContext(script(interactions), context);
    vm.runInNewContext(script(interactions), context); // Initialization must remain idempotent.
    edit.dispatch('click');
    assert.equal(schedulePanel.hidden, false);
    assert.equal(input.focused, true);
    assert.equal(toggle.getAttribute('aria-expanded'), 'true');
    toggle.dispatch('click');
    assert.equal(schedulePanel.hidden, true);
    assert.equal(edit.getAttribute('aria-expanded'), 'false');
    edit.dispatch('click');
    edit.dispatch('click');
    assert.equal(schedulePanel.hidden, false);
    preparationToggle.dispatch('click');
    assert.equal(preparationPanel.hidden, false);
    cancel.dispatch('click');
    assert.equal(schedulePanel.hidden, true);
    assert.equal(preparationPanel.hidden, false);
    assert.equal(resets, 1);
    assert.equal(edit.focused, true);
    assert.doesNotMatch(interactions, /fetch\(|\.submit\(|requestSubmit\(/);
});

test('scoped styles preserve hidden panels and keep enabled colors separate from disabled behavior', () => {
    assert.match(styles, /\.release-flow-page \[hidden\] \{ display: none !important; \}/);
    assert.match(styles, /\.release-context-grid \{[^}]*repeat\(2, minmax\(0, 1fr\)\)[^}]*align-items: stretch/);
    assert.match(styles, /@media \(max-width: 640px\)/);
    assert.match(styles, /\.button\.ui-pressable\.release-primary:not\(:disabled\):hover/);
    assert.match(styles, /\.button\.ui-pressable\.release-outline:not\(:disabled\):hover/);
    assert.doesNotMatch(styles, /\.release-primary:hover|\.release-outline:hover/);
});

test('compact release tracker is opt-in and only overrides labels and icons', () => {
    const tracker = read('resources/views/components/request-progress-tracker.blade.php');
    assert.match(tracker, /'releaseView' => false/);
    assert.match(processLayout, /:show-current-status="false" :compact="true" :release-view="true"/);
    const override = tracker.match(/if \(\$releaseView\) \{([\s\S]*?)\}/)[1];
    /* Step indices are named variables now (off-campus requests insert an extra step), not hardcoded literals. */
    assert.match(override, /\$steps\[\$releaseStepIndex\]\['label'\] = 'Release'/);
    assert.match(override, /\$steps\[\$returnStepIndex\]\['icon'\] = 'chevron-right'/);
    assert.doesNotMatch(override, /status|completed|current|pending|date/i);
});

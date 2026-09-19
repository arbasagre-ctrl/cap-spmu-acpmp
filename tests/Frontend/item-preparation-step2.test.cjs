const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const preparation = read('resources/views/custody/partials/item-preparation-form.blade.php');
const process = read('resources/views/custody/partials/release-process.blade.php');
const interactions = read('resources/views/custody/partials/release-process-interactions.blade.php');
const styles = read('resources/views/custody/partials/release-process-styles.blade.php');
const show = read('resources/views/custody/show.blade.php');
const controller = read('app/Http/Controllers/CustodyController.php');
const workflow = read('app/Services/RequestWorkflowService.php');


test('Step 2 uses one concise universal instruction and keeps the normal path simple', () => {
    assert.match(process, /<h3>Item Preparation<\/h3>/);
    assert.match(process, /Report a discrepancy only when the physical stock does not match Inventory\./);
    assert.match(preparation, /Pending Preparation/);
    assert.match(preparation, /Confirm Items Prepared/);
    assert.match(preparation, /Report Inventory Discrepancy/);
    assert.doesNotMatch(preparation, /All approved items must be physically ready before release/);
    assert.doesNotMatch(preparation, /Record only what you physically observed/);
});


test('AO discrepancy form shows only fields applicable to the selected discrepancy', () => {
    assert.match(preparation, /data-preparation-issue-type/);
    assert.match(preparation, /data-preparation-quantity-field hidden/);
    assert.match(preparation, /data-preparation-condition-field hidden/);
    assert.match(preparation, /data-preparation-details-field hidden/);
    assert.match(preparation, /Physically ready quantity/);
    assert.match(preparation, /Condition observed/);
    assert.match(preparation, /Remarks \(Optional\)/);
    assert.match(interactions, /value === 'QUANTITY_AVAILABILITY'/);
    assert.match(interactions, /value === 'PHYSICAL_CONDITION'/);
    assert.match(interactions, /value === 'OTHER'/);
    assert.match(interactions, /setFieldState(detailsField, detailsInput, hasOptionalRemarks || otherIssue, otherIssue)/);
});


test('reported discrepancy blocks preparation and preserves the AO recheck path', () => {
    assert.match(preparation, /Inventory Review Required/);
    assert.match(preparation, /Inventory Reviewed — Recheck Item/);
    assert.match(preparation, /@disabled\(\$openPreparationIssues->isNotEmpty\(\)\)/);
    assert.match(process, /Physical Release is unavailable while the reported inventory discrepancy is under review/);
});


test('admin uses one resolution control instead of duplicate resolution forms before pickup expiry', () => {
    assert.match(show, /Inventory Discrepancy Review/);
    assert.match(show, /data-preparation-resolution-form/);
    assert.match(show, /Resolved — AO Recheck Required/);
    assert.match(show, /Unable to Fulfill Approved Request/);
    assert.match(show, /Remarks \(Optional\)/);
    assert.match(show, /data-preparation-unfulfillable-confirmation hidden/);
    assert.match(show, /Confirm Inventory Reviewed/);
    assert.match(controller, /cancelApprovedForPreparationDiscrepancy/);
    assert.match(workflow, /PREPARATION_ISSUE_CLOSED_UNFULFILLED/);
});


test('unable-to-fulfill remains an SPMU-side cancellation with no partial release or missed pickup', () => {
    assert.match(show, /complete approved quantity cannot be provided and partial release is not allowed/i);
    assert.match(show, /No borrower missed-pickup record was created/);
    assert.match(workflow, /borrower_missed_pickup' => false/);
    assert.match(workflow, /partial_release_allowed' => false/);
    assert.match(workflow, /reserved quantity has been returned to inventory/);
});


test('Step 2 actions keep the universal action row and chevron panel pattern', () => {
    const rowStart = preparation.indexOf('release-preparation-action-row');
    const panelStart = preparation.indexOf('<div\n                class="release-preparation-report-panel"', rowStart);
    const actionRow = preparation.slice(rowStart, panelStart);

    assert.ok(rowStart >= 0 && panelStart > rowStart);
    assert.ok(actionRow.indexOf('Report Inventory Discrepancy') < actionRow.indexOf('Confirm Items Prepared'));
    assert.match(actionRow, /data-release-panel-toggle/);
    assert.match(actionRow, /aria-controls="release-preparation-report-panel"/);
    assert.match(actionRow, /<x-icon name="chevron-down" size="16" \/>/);
    assert.match(styles, /release-preparation-report-toggle\[aria-expanded="true"\] \.ui-icon/);
});

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const accountability = fs.readFileSync(path.join(root, 'resources/views/accountability/index.blade.php'), 'utf8');
const inventory = fs.readFileSync(path.join(root, 'resources/views/inventory/show.blade.php'), 'utf8');


test('Head decision records one specific compliance action only when compliance is selected', () => {
  assert.match(accountability, /Property Compliance Required/);
  assert.match(accountability, /Required Compliance Action/);
  assert.match(accountability, /value="REPAIR"/);
  assert.match(accountability, /value="REPLACEMENT"/);
  assert.match(accountability, /value="RECOVERY"/);
  assert.match(accountability, /data-compliance-action-field hidden/);
  assert.match(accountability, /data-rslddp-field hidden/);
  assert.match(accountability, /outcome\?\.value === 'COMPLIANCE_REQUIRED'/);
});

test('AO verification is one-step and does not ask for duplicate remarks or a second decision', () => {
  assert.doesNotMatch(accountability, /Verification Remarks \(Optional\)/);
  assert.doesNotMatch(accountability, /Add a note only if needed\./);
  assert.match(accountability, /Confirm Replacement Received/);
  assert.match(accountability, /Confirm Repair/);
  assert.match(accountability, /Confirm Item Recovery/);
  assert.doesNotMatch(accountability, /accountability-resolution-note">If no other property obligation remains/);
});

test('Inventory Overview does not duplicate repair replacement or recovery encoding', () => {
  assert.doesNotMatch(inventory, /Repair, replacement, and recovery are posted automatically after Action Officer verification\./);
  assert.doesNotMatch(inventory, /<option value="RETURN_TO_SERVICE"/);
  assert.doesNotMatch(inventory, /<option value="REPLACEMENT_RECEIVED"/);
  assert.doesNotMatch(inventory, /<option value="ITEM_RECOVERED"/);
  assert.match(inventory, /<option value="WRITE_OFF_RETIRED"/);
});

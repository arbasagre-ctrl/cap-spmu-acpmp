const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const show = fs.readFileSync(path.join(root, 'resources/views/inventory/show.blade.php'), 'utf8');
const row = fs.readFileSync(path.join(root, 'resources/views/inventory/partials/operations-row.blade.php'), 'utf8');
const index = fs.readFileSync(path.join(root, 'resources/views/inventory/index.blade.php'), 'utf8');
const interactions = fs.readFileSync(path.join(root, 'resources/views/inventory/partials/operations-interactions.blade.php'), 'utf8');
const form = fs.readFileSync(path.join(root, 'resources/views/inventory/form.blade.php'), 'utf8');
const inventoryController = fs.readFileSync(path.join(root, 'app/Http/Controllers/InventoryController.php'), 'utf8');
const inventoryService = fs.readFileSync(path.join(root, 'app/Services/InventoryService.php'), 'utf8');
const custodyService = fs.readFileSync(path.join(root, 'app/Services/CustodyService.php'), 'utf8');

test('inventory overview uses one formal adjustment action without a plus icon', () => {
  assert.match(show, /Record Inventory Adjustment/);
  assert.match(show, /data-inventory-adjustment-toggle/);
  assert.match(show, /chevron-down/);
  assert.doesNotMatch(show, /inventory-adjustment[^\n]*plus(?:-circle)?/i);
  assert.match(show, /Place Stock Under Maintenance/);
  assert.match(show, /Return Maintenance Stock to Service/);
  assert.match(show, /Retire \/ Condemn Maintenance Stock/);
});


test('inventory adjustment action uses grouped controlled choices with context-sensitive fields', () => {
  assert.match(show, /<optgroup label="Stock Adjustment">/);
  assert.match(show, /<optgroup label="Physical Condition">/);
  assert.match(show, /<optgroup label="Accountability Disposition">/);
  assert.doesNotMatch(show, /data-inventory-adjustment-action-help/);
  assert.match(show, /data-inventory-quantity-label/);
  assert.match(show, /Related accountability incident/);
  assert.match(show, /Stock Retired \/ Written Off/);
  assert.doesNotMatch(show, />Returned to Service \/ Repaired</);
  assert.doesNotMatch(show, />Replacement Received</);
  assert.doesNotMatch(show, />Item Recovered</);
  assert.doesNotMatch(show, /const actionGuidance = \{/);
  assert.match(show, /const quantityLabels = \{/);
  assert.match(show, /form\.reset\(\);/);
});

test('inventory adjustment fields keep paired controls vertically aligned', () => {
  assert.match(show, /\.inventory-adjustment-form > label \{[\s\S]*align-self: start;[\s\S]*align-content: start;/);
  assert.match(show, /\.inventory-adjustment-form select, \.inventory-adjustment-form input \{ min-height: 48px; \}/);
  assert.match(show, /\.inventory-adjustment-form > label\[hidden\] \{ display: none !important; \}/);
});

test('operational Available excludes current approved reservations', () => {
  const borrowerAvailable = row.indexOf("$balance['borrower_available']");
  const currentAvailable = row.indexOf("$balance['current_available']");
  assert.ok(borrowerAvailable >= 0);
  assert.ok(currentAvailable > borrowerAvailable);
});

test('SPMU inventory includes a universal record-status filter', () => {
  assert.match(index, /id="spmu-inventory-status"/);
  assert.match(index, /Inactive \/ archived records/);
  assert.match(interactions, /selectedStatus/);
  assert.match(row, /data-status=/);
});

test('existing item edit delegates physical stock changes to Inventory Overview without duplicate instructions', () => {
  assert.match(form, /Managed from Inventory Overview/);
  assert.match(form, /View Inventory Overview/);
  assert.doesNotMatch(form, /Use <strong>Record Inventory Adjustment<\/strong>/);
  assert.doesNotMatch(form, /Accountability repair, replacement, and recovery are posted automatically/);
});


test('inventory adjustment uses action-specific basis fields instead of one always-required generic reason', () => {
  assert.match(show, /data-inventory-reason-field hidden/);
  assert.match(show, /const reasonConfig = \{/);
  assert.match(show, /STOCK_ADDITION:[\s\S]*label: 'Source \/ Reference'[\s\S]*required: true/);
  assert.match(show, /PHYSICAL_COUNT_CORRECTION:[\s\S]*label: 'Reason for Correction'[\s\S]*required: true/);
  assert.doesNotMatch(show, /RETURN_MAINTENANCE_TO_SERVICE:[\s\S]*label: 'Verification Note'/);
  assert.doesNotMatch(show, /REPLACEMENT_RECEIVED:/);
  assert.doesNotMatch(show, /ITEM_RECOVERED:/);
  assert.doesNotMatch(show, /RETURN_TO_SERVICE:/);
  assert.match(show, /WRITE_OFF_RETIRED:[\s\S]*label: 'Write-off Basis'[\s\S]*required: true/);
  assert.doesNotMatch(show, /WRITE_OFF_RETIRED: new Set\(\[[^\]]*REPLACEMENT_REQUIRED/);
  assert.doesNotMatch(show, /<textarea name="reason" rows="3" required/);
});


test('inventory adjustment removes duplicate helper copy and manual compliance restoration actions', () => {
  assert.doesNotMatch(show, /Repair, replacement, and recovery are posted automatically after Action Officer verification\./);
  assert.doesNotMatch(show, /inventory-adjustment-guidance/);
  assert.doesNotMatch(show, /The related accountability incident is already the formal reference\./);
  assert.doesNotMatch(show, /Use when a replacement for an accountability item has been physically received/);
  assert.doesNotMatch(show, /data-inventory-reason-help/);
  assert.match(show, /Select accountability incident/);
  assert.doesNotMatch(show, /Select inventory exception/);
});

test('inventory create and edit use context-appropriate audit fields', () => {
  assert.match(form, /Reason for Change/);
  assert.match(form, /name="change_reason"[\s\S]*required/);
  assert.match(form, /Initial Stock Source \/ Reference <small>\(Optional\)<\/small>/);
  assert.match(form, /name="initial_stock_source"/);
  assert.doesNotMatch(form, /Mandatory change reason/);
});

test('manual maintenance is stock-state aware and remains available for physically damaged linen', () => {
  assert.match(show, /\$canPlaceUnderMaintenance/);
  assert.match(show, /\$canReturnMaintenanceToService/);
  assert.match(show, /\$canRetireMaintenanceStock/);
  assert.match(show, /\$canWriteOffRetired/);
  assert.match(show, /@if\(\$canPlaceUnderMaintenance \|\| \$canReturnMaintenanceToService \|\| \$canRetireMaintenanceStock\)/);
  assert.match(show, /@if\(\$canWriteOffRetired\)/);
  assert.match(inventoryService, /uses_laundry_workflow/);
  assert.match(inventoryService, /can_place_under_maintenance'[\s\S]*\$item->active/);
  assert.doesNotMatch(inventoryService, /can_place_under_maintenance'[\s\S]{0,120}! \$usesLaundryWorkflow/);
  assert.doesNotMatch(inventoryService, /Laundry-managed items use the Laundry and Accountability workflows instead of manual maintenance adjustments/);
});

test('manual accountability write-off is limited to final resolved cases', () => {
  assert.match(inventoryController, /eligibleWriteOffSources/);
  assert.match(inventoryController, /\['RESOLVED', 'CLOSED'\]/);
  assert.match(inventoryService, /Stock write-off is available only after the related accountability case has a final resolution/);
  assert.match(show, /@foreach\(\$writeOffSources as \$source\)/);
});

test('laundry-managed inventory stays on the laundry workflow and filter', () => {
  assert.match(show, /@if\(\$item->laundry_required\)[\s\S]*IN_LAUNDRY/);
  assert.match(inventoryController, /! \$inventory->laundry_required && \$historyStatus === 'IN_LAUNDRY'/);
  assert.match(custodyService, /LAUNDRY_COMPLETION/);
  assert.match(custodyService, /'LAUNDRY',[\s\S]*'AVAILABLE'/);
});


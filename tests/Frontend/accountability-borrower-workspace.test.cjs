const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const workspace = fs.readFileSync(path.join(root, 'resources/views/accountability/borrower-workspace.blade.php'), 'utf8');
const resolvedHistory = fs.readFileSync(path.join(root, 'resources/views/accountability/partials/resolved-history.blade.php'), 'utf8');

test('borrower Accountability workspace stays grouped and does not route normal case actions back to the old expandable table', () => {
  assert.match(workspace, /groupBy\(fn \(\$record\) => \$record->custody_transaction_id/);
  assert.match(workspace, /Responsible Now/);
  assert.match(workspace, /No action required at this stage/);
  assert.doesNotMatch(workspace, /data-case-toggle/);
  assert.doesNotMatch(workspace, /route\('accountability\.index', \['borrower'/);
  assert.doesNotMatch(workspace, /View Case/);
});

test('role-owned Accountability actions use focused controls instead of forward-arrow navigation', () => {
  assert.match(workspace, /data-accountability-dialog/);
  assert.match(workspace, />Review Assessment</);
  assert.match(workspace, /Confirm \/ Clear/);
  assert.match(workspace, />Record Verification</);
  assert.match(workspace, />Record Receipt</);
  assert.match(workspace, />Resolve Case</);
  assert.doesNotMatch(workspace, /→/);
});

test('workspace uses compact readable sizing and wraps long status/action text', () => {
  assert.match(workspace, /font-size: clamp\(24px, 1\.9vw, 29px\)/);
  assert.match(workspace, /min-height: 108px/);
  assert.match(workspace, /overflow-wrap: anywhere/);
  assert.match(workspace, /white-space: normal/);
});


test('documents tab uses its own left-aligned compact list instead of dashboard queue alignment', () => {
  assert.match(workspace, /accountability-document-list/);
  assert.match(workspace, /accountability-document-row/);
  assert.match(workspace, /accountability-document-copy/);
  assert.match(workspace, /text-align: left/);
  assert.doesNotMatch(workspace, /class="dashboard-primary-queue-row" style="grid-template-columns: 34px/);
});

test('embedded borrower history suppresses the duplicate resolved-accountability heading', () => {
  assert.match(workspace, /'embeddedInBorrowerWorkspace' => true/);
  assert.match(resolvedHistory, /@unless\(\$embeddedInBorrowerWorkspace\)/);
  assert.match(resolvedHistory, /accountability-history-embedded/);
});

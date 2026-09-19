const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const routes = fs.readFileSync(path.join(root, 'routes/web.php'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/CustodyController.php'), 'utf8');
const service = fs.readFileSync(path.join(root, 'app/Services/CustodyService.php'), 'utf8');

test('legacy Step 2 quantity and borrower acknowledgement endpoints are retired', () => {
  assert.doesNotMatch(routes, /custody\.quantities/);
  assert.doesNotMatch(routes, /custody\.acknowledge/);
  assert.doesNotMatch(controller, /public function quantities\(/);
  assert.doesNotMatch(controller, /public function acknowledge\(/);
  assert.doesNotMatch(service, /public function updateReceiptQuantities\(/);
  assert.doesNotMatch(service, /public function acknowledge\(/);
});

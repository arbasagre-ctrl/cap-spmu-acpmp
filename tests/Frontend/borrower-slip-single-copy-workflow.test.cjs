const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const custody = fs.readFileSync(path.join(root, 'app/Services/CustodyService.php'), 'utf8');

test('release and return preserve the approval-time Borrower Slip', () => {
  const releaseStart = custody.indexOf('public function release(');
  const returnStart = custody.indexOf('public function receiveReturn(', releaseStart);
  assert.notEqual(releaseStart, -1);
  assert.notEqual(returnStart, -1);
  const release = custody.slice(releaseStart, returnStart);
  assert.doesNotMatch(release, /documents->borrowerSlip\(/);
  assert.match(release, /single official travelling copy/);

  const nextMethod = custody.indexOf('public function requestEarlyReturn(', returnStart + 20);
  const returnBlock = custody.slice(returnStart, nextMethod === -1 ? custody.length : nextMethod);
  assert.doesNotMatch(returnBlock, /documents->borrowerSlip\(/);
  assert.match(returnBlock, /approval-time Borrower Slip/);
});

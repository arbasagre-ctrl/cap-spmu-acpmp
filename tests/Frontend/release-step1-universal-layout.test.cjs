const fs = require('fs');
const path = require('path');
const assert = require('assert');

const root = path.resolve(__dirname, '../..');
const pickup = fs.readFileSync(path.join(root, 'resources/views/custody/partials/pickup-schedule-form.blade.php'), 'utf8');
const process = fs.readFileSync(path.join(root, 'resources/views/custody/partials/release-process.blade.php'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'resources/views/custody/partials/release-process-styles.blade.php'), 'utf8');

const tests = [
  ['normal expanded Step 1 does not repeat the pickup schedule card', () => {
    assert(!pickup.includes('release-schedule-suggestion'));
    assert(!pickup.includes('<strong>Pickup &amp; Issuance Schedule</strong>'));
    assert(!pickup.includes('<strong>System Pickup &amp; Issuance Schedule</strong>'));
  }],
  ['borrower notification uses a neutral detail row instead of a colored success panel', () => {
    assert(pickup.includes('release-schedule-detail-label">Borrower notification'));
    assert(!pickup.includes('notice success compact'));
    assert(!pickup.includes('✓ Borrower notified'));
  }],
  ['schedule exceptions use the same neutral section treatment', () => {
    assert(pickup.includes('release-schedule-exception'));
    assert(!pickup.includes('notice warning compact release-pickup-exception-actions'));
  }],
  ['Step 1 header remains the single place showing date and time', () => {
    assert(process.includes('release-step-schedule'));
    assert(process.includes("scheduled_release_at->format('M j, Y')"));
  }],
  ['expanded panel language is generic and not a duplicate schedule label', () => {
    assert(process.includes('aria-label="Toggle pickup details"'));
    assert(process.includes('title="Show or hide pickup details"'));
  }],
  ['neutral schedule styles contain no info or success backgrounds', () => {
    assert(styles.includes('.release-schedule-detail-row'));
    assert(styles.includes('.release-schedule-exception'));
    assert(!styles.includes('.release-schedule-suggestion {'));
  }],
];

let passed = 0;
for (const [name, fn] of tests) {
  try { fn(); passed++; console.log(`PASS ${name}`); }
  catch (err) { console.error(`FAIL ${name}`); console.error(err.message); process.exitCode = 1; }
}
console.log(`${passed}/${tests.length} passed`);

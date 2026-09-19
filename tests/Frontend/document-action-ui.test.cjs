const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const viewsRoot = path.join(root, 'resources/views');

function bladeFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return bladeFiles(full);
    return entry.name.endsWith('.blade.php') ? [full] : [];
  });
}

const allBlade = bladeFiles(viewsRoot)
  .map((file) => fs.readFileSync(file, 'utf8'))
  .join('\n');

const obligationService = fs.readFileSync(path.join(root, 'app/Services/BorrowerObligationService.php'), 'utf8');
const requestShow = fs.readFileSync(path.join(viewsRoot, 'requests/show.blade.php'), 'utf8');
const requestOperational = fs.readFileSync(path.join(viewsRoot, 'requests/partials/operational-details.blade.php'), 'utf8');
const releaseProcess = fs.readFileSync(path.join(viewsRoot, 'custody/partials/release-process.blade.php'), 'utf8');
const returnWorkspace = fs.readFileSync(path.join(viewsRoot, 'custody/partials/return-workspace.blade.php'), 'utf8');
const reviewViewer = fs.readFileSync(path.join(viewsRoot, 'components/document-review-viewer.blade.php'), 'utf8');
const routes = fs.readFileSync(path.join(root, 'routes/web.php'), 'utf8');

const legacyDocumentActions = [
  'Open Document',
  'Open Borrower Slip',
  'Open Laundry Form',
  'Open Gate Pass',
  'Open Billing Statement',
  'Open Official Billing Statement',
  'Open Late Return Billing Statement',
  'Open RSLDDP',
  'Open Compliance Notice',
  'Open Restriction Notice',
  'Open Suspension Notice',
  'Open Administrative Sanction Notice',
  'Open Late Return Notice',
  'Open Accomplished Scan',
  'Open Scanned Cashier Receipt',
  'View Document',
  'View Form',
  'View PDF',
  'View uploaded file',
  'Open original',
];

test('all user-facing document actions use Preview wording', () => {
  for (const legacy of legacyDocumentActions) {
    assert.equal(allBlade.includes(legacy), false, `legacy action remains in Blade: ${legacy}`);
    assert.equal(obligationService.includes(legacy), false, `legacy action remains in obligation service: ${legacy}`);
  }
  assert.equal(allBlade.includes('Preview'), true);
});

test('document lists do not expose a duplicate generated-document Download button', () => {
  assert.equal(allBlade.includes("route('documents.download'"), false);
});

test('admin and operational request document lists use the preview wrappers', () => {
  assert.match(requestOperational, /route\('files\.preview', \$doc->file/);
  assert.match(requestOperational, /route\('documents\.preview', \$borrowerSlipDocument\)/);
  assert.match(requestOperational, /route\('documents\.preview', \$laundryFormDocument\)/);
  assert.match(requestOperational, /route\('documents\.preview', \$gatePassDocument\)/);
  assert.doesNotMatch(requestOperational, /target="_blank"/);
});

test('protected uploaded files have a defined in-system preview route', () => {
  assert.match(routes, /protected-files\/\{file\}\/preview/);
  assert.match(routes, /name\('files\.preview'\)/);
});

test('non-applicable documents are hidden and unavailable applicable documents say Not available', () => {
  assert.match(requestShow, /@if\(\$requestHasLaundry\)/);
  assert.match(requestShow, /@if\(\$requestHasOffCampus\)/);
  assert.doesNotMatch(requestShow, />Not applicable<\/span>/);
  assert.match(requestShow, /Not available/);
  assert.match(releaseProcess, /@if\(\$hasOffCampusItem\)/);
  assert.match(releaseProcess, /@(?:if|elseif)\(\$hasLaundryItem\)/);
  assert.match(returnWorkspace, /@if\(\$hasOperationalReturnDocuments\)/);
});

test('preview component relies on embedded PDF controls instead of a duplicate outer download action', () => {
  assert.doesNotMatch(reviewViewer, /Open original/);
  assert.doesNotMatch(reviewViewer, /downloadUrl/);
});

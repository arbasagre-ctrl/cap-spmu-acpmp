const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const viewer = fs.readFileSync(
  path.join(root, 'resources/views/components/document-review-viewer.blade.php'),
  'utf8'
);
const geometry = fs.readFileSync(
  path.join(root, 'app/Services/DocumentPreviewGeometryService.php'),
  'utf8'
);

test('standalone preview is driven by physical file geometry, not document names', () => {
  assert.match(viewer, /DocumentPreviewGeometryService/);
  assert.match(viewer, /data-preview-orientation/);
  assert.match(viewer, /data-preview-orientation="portrait"/);
  assert.match(viewer, /data-preview-orientation="landscape"/);
  assert.doesNotMatch(viewer, /BORROWER[_\s-]?SLIP/i);
  assert.doesNotMatch(viewer, /LAUNDRY[_\s-]?FORM/i);
});

test('pdf geometry detection uses the physical page box and rotation', () => {
  assert.match(geometry, /CropBox/);
  assert.match(geometry, /MediaBox/);
  assert.match(geometry, /Rotate/);
  assert.match(geometry, /Parent/);
});

test('image previews derive orientation from their natural dimensions', () => {
  assert.match(viewer, /naturalWidth/);
  assert.match(viewer, /naturalHeight/);
  assert.match(viewer, /syncImageOrientation/);
});

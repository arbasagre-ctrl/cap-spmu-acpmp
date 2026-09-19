const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const viewPath = path.resolve(__dirname, '../../resources/views/requests/form.blade.php');
const view = fs.readFileSync(viewPath, 'utf8');

test('request E-sign submit keeps its original label after confirmation', () => {
    assert.match(view, /id="request-submit-button"[\s\S]*?E-sign &amp; Submit to SPMU/);

    const confirmHandler = view.match(
        /confirmSubmitButton\?\.addEventListener\('click',[\s\S]*?\n\s*}\);\n\s*submitDialog\?\.addEventListener/
    );

    assert.ok(confirmHandler, 'confirmation handler should exist');
    assert.match(confirmHandler[0], /submitButton\.disabled = true;/);
    assert.doesNotMatch(confirmHandler[0], /submitButton\.textContent\s*=\s*['"]Processing\.\.\.['"]/);
});

test('pre-confirmation submit still opts out of the global duplicate-submit lock', () => {
    assert.match(view, /form\.dataset\.allowRepeatedSubmit = 'true';/);
    assert.match(view, /delete form\.dataset\.allowRepeatedSubmit;/);
});

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const partial = (name) => read(`resources/views/laundry/partials/completed-${name}.blade.php`);
const page = read('resources/views/laundry/completed.blade.php');
const row = partial('case-row');
const back = partial('back-link');
const pagination = partial('pagination');
const styles = partial('styles');
const interactions = partial('interactions');
const script = interactions.match(/<script>([\s\S]*?)<\/script>/)[1];

function element(dataset = {}) {
    const handlers = new Map();
    return {
        dataset,
        value: '',
        textContent: '',
        hidden: false,
        focused: false,
        addEventListener(name, callback) { handlers.set(name, [...(handlers.get(name) || []), callback]); },
        dispatch(name) { (handlers.get(name) || []).forEach((callback) => callback()); },
        focus() { this.focused = true; },
    };
}

function mount({ paginated = false, withRecords = true } = {}) {
    const records = withRecords ? [
        element({ search: 'LND-2026-00001 Juan Cruz tablecloth', outcomes: 'available' }),
        element({ search: 'LND-2026-00002 Maria Santos linen', outcomes: 'maintenance' }),
        element({ search: 'LND-2026-00003 Ana Reyes tablecloth linen', outcomes: 'available maintenance' }),
        element({ search: 'LND-2026-00004 No laundry required', outcomes: 'not-needed' }),
        element({ search: 'LND-2026-00005 Old record', outcomes: 'unrecorded' }),
    ] : [];
    const search = element();
    const outcome = element();
    const empty = element();
    const count = element({
        total: paginated ? '25' : String(records.length),
        first: records.length ? '1' : '0',
        last: String(records.length),
        paginated: String(paginated),
    });
    const reset = element();
    const browser = {
        dataset: {},
        querySelector: (selector) => ({
            '[data-completed-search]': search,
            '[data-completed-outcome]': outcome,
            '[data-completed-empty]': empty,
            '[data-completed-count]': count,
            '[data-completed-reset]': reset,
        })[selector],
        querySelectorAll: () => records,
    };
    const context = { document: { querySelector: () => browser } };
    vm.runInNewContext(script, context);
    return { records, search, outcome, empty, count, reset, context };
}

test('completed laundry has separate empty and populated states matching the reference', () => {
    assert.match(page, /<p class="eyebrow">Laundry Operations<\/p>/);
    assert.match(page, /Review completed laundry cases and their final disposition\./);
    assert.match(page, /@if\(\$jobs->total\(\) === 0\)\s+<section class="card completed-laundry-empty"/);
    assert.match(page, /No completed laundry cases yet\./);
    assert.match(page, /Completed laundry cases will appear here after processing is finalized\./);
    assert.match(page, /@include\('laundry\.partials\.completed-empty-illustration'\)/);
    assert.match(page, /@else\s+<section class="card completed-laundry-card"/);
    assert.equal(page.split("@include('laundry.partials.completed-back-link')").length - 1, 2);
    assert.match(back, /route\('laundry\.index'\)/);
    assert.match(back, /Back to Active Laundry/);
    assert.match(page, /Completed cases are archived for record keeping and inventory management\./);
    assert.doesNotMatch(page, /SCREEN 1|SCREEN 2|No completed internal Laundry/);
});

test('table shows live case identity, borrower, units, dates and the original details route', () => {
    for (const heading of ['Case ID', 'Borrower', 'Items', 'Completed Date', 'Outcome', 'Action']) {
        assert.ok(page.includes(`<th scope="col">${heading}</th>`));
    }
    assert.match(page, /@foreach\(\$jobs as \$job\)/);
    assert.match(row, /str_pad\(\(string\) \$job->id, 5, '0', STR_PAD_LEFT\)/);
    assert.match(row, /\$job->created_at->format\('Y'\)/);
    assert.match(row, /\$job->custody\?->borrower\?->full_name/);
    assert.match(row, /unit_snapshot/);
    assert.match(row, /\$hasRecordedQuantities\s+\? \$job->lines->groupBy/);
    assert.match(row, /\$job->completed_at \?: \$job->worker_completed_at/);
    assert.match(row, /toIso8601String\(\)/);
    assert.match(row, /route\('laundry\.show', \$job\)/);
    assert.doesNotMatch(row, /LND-2026-00012|Juan Dela Cruz|now\(\)/);
});

test('outcomes reflect recorded quantities, including mixed and unknown results', () => {
    assert.match(row, /\$cleanedQuantity = \(int\) \$job->lines->sum\('completed_quantity'\)/);
    assert.match(row, /where\('issue_type', 'DAMAGED'\)->sum\('affected_quantity'\)/);
    assert.match(row, /every\(fn \(\$line\) => \$line->received_quantity !== null\)/);
    assert.match(row, /\$noProcessingRequired = \$hasRecordedQuantities && \$receivedQuantity === 0 && \$job->worker_received_at/);
    assert.match(row, /@if\(\$cleanedQuantity > 0\)[\s\S]*?@endif\s+@if\(\$maintenanceQuantity > 0\)/);
    assert.match(row, /\$cleanedQuantity > 0 \? 'available' : null/);
    assert.match(row, /\$maintenanceQuantity > 0 \? 'maintenance' : null/);
    assert.match(row, /'not-needed' : 'unrecorded'/);
    assert.match(row, /Outcome not recorded/);
    assert.doesNotMatch(row, /status === 'LAUNDRY_COMPLETED'[^;]*'available'/);
});

test('numbered pagination keeps server URLs and non-interactive boundary states', () => {
    assert.match(page, /\$jobs->onEachSide\(1\)->links\('laundry\.partials\.completed-pagination'\)/);
    assert.match(pagination, /\$paginator->previousPageUrl\(\)/);
    assert.match(pagination, /\$paginator->nextPageUrl\(\)/);
    assert.match(pagination, /@foreach\(\$elements as \$element\)/);
    assert.match(pagination, /href="\{\{ \$url \}\}"/);
    assert.match(pagination, /aria-current="page"/);
    assert.match(pagination, /@if\(\$paginator->onFirstPage\(\)\)\s+<span class="completed-page-link" aria-disabled="true"/);
    assert.match(pagination, /@else\s+<span class="completed-page-link" aria-disabled="true" aria-label="Next page"/);
    assert.doesNotMatch(pagination, /<a[^>]+aria-disabled|href="#"/);
});

test('search and outcome filters compose and keep mixed outcomes discoverable', () => {
    const ui = mount();
    assert.equal(ui.count.textContent, 'Showing 1 to 5 of 5 completed cases');
    ui.outcome.value = 'available';
    ui.outcome.dispatch('change');
    assert.deepEqual(ui.records.map((record) => record.hidden), [false, true, false, true, true]);
    ui.outcome.value = 'maintenance';
    ui.outcome.dispatch('change');
    assert.deepEqual(ui.records.map((record) => record.hidden), [true, false, false, true, true]);
    ui.search.value = '  ANA   TABLECLOTH ';
    ui.search.dispatch('input');
    assert.deepEqual(ui.records.map((record) => record.hidden), [true, true, false, true, true]);
    assert.equal(ui.count.textContent, 'Showing 1 of 5 completed cases');
    ui.search.value = 'not found';
    ui.search.dispatch('input');
    assert.equal(ui.empty.hidden, false);
});

test('unknown and no-processing results are not presented as available', () => {
    const ui = mount();
    ui.outcome.value = 'unrecorded';
    ui.outcome.dispatch('change');
    assert.deepEqual(ui.records.map((record) => record.hidden), [true, true, true, true, false]);
    ui.outcome.value = 'not-needed';
    ui.outcome.dispatch('change');
    assert.deepEqual(ui.records.map((record) => record.hidden), [true, true, true, false, true]);
});

test('clear filters restores the original page range and focus, with safe repeated initialization', () => {
    const ui = mount({ paginated: true });
    vm.runInNewContext(script, ui.context);
    ui.search.value = 'Ana';
    ui.outcome.value = 'maintenance';
    ui.search.dispatch('input');
    assert.equal(ui.count.textContent, 'Showing 1 of 5 cases on this page (25 total)');
    ui.reset.dispatch('click');
    assert.equal(ui.count.textContent, 'Showing 1 to 5 of 25 completed cases');
    assert.equal(ui.search.value, '');
    assert.equal(ui.outcome.value, '');
    assert.equal(ui.search.focused, true);
    assert.equal(ui.empty.hidden, true);
    assert.ok(ui.records.every((record) => !record.hidden));
});

test('true empty pages and out-of-range server pages are handled without errors', () => {
    assert.doesNotThrow(() => vm.runInNewContext(script, {
        document: { querySelector: () => ({ dataset: {}, querySelector: () => null }) },
    }));
    const ui = mount({ withRecords: false, paginated: true });
    assert.equal(ui.count.textContent, 'Showing 0 to 0 of 25 completed cases');
    assert.equal(ui.empty.hidden, false);
    assert.match(page, /Search and outcome filters apply to this page\./);
});

test('completed UI contains no processing forms or workflow mutations', () => {
    assert.doesNotMatch(page + row + back + pagination, /<form|->(?:save|update|delete|create)\(|DB::|FOR_SPMU_FINAL_CHECK|AWAITING_FINAL_FORM_UPLOAD/);
    assert.doesNotMatch(interactions, /fetch\(|XMLHttpRequest|\.submit\(|requestSubmit\(/);
    assert.match(page, /type="button" data-completed-reset/);
    assert.match(page, /role="status" aria-live="polite"/);
});

test('responsive styles stay scoped and use valid theme variables', () => {
    assert.match(styles, /\.completed-laundry \[hidden\] \{ display: none !important; \}/);
    assert.match(styles, /\.completed-laundry-table-wrap \{[^}]*overflow-x: auto/);
    assert.match(styles, /@media \(max-width: 700px\)/);
    assert.match(styles, /@media \(max-width: 450px\)/);
    assert.match(styles, /\.completed-laundry \.button\.ui-pressable\.secondary:not\(:disabled\):hover/);
    assert.match(styles, /prefers-reduced-motion/);
    const theme = read('public/css/app.css');
    const variables = new Set([...`${theme}\n${styles}`.matchAll(/(--[\w-]+)\s*:/g)].map((match) => match[1]));
    for (const [, variable] of styles.matchAll(/var\((--[\w-]+)/g)) {
        assert.ok(variables.has(variable), `Undefined CSS variable: ${variable}`);
    }
});

test('the completed Blade branches remain balanced', () => {
    for (const source of [page, row, pagination]) {
        const markup = source.replace(/@php[\s\S]*?@endphp/g, '');
        const stack = [];
        const pairs = { endif: 'if', endforeach: 'foreach', endsection: 'section' };
        for (const [, directive] of markup.matchAll(/@(if|endif|foreach|endforeach|section|endsection)\b/g)) {
            if (Object.hasOwn(pairs, directive)) assert.equal(stack.pop(), pairs[directive]);
            else stack.push(directive);
        }
        assert.deepEqual(stack, []);
    }
});

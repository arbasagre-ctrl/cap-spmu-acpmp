const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const partial = (name) => read(`resources/views/laundry/partials/${name}.blade.php`);
const page = read('resources/views/laundry/index.blade.php');
const guide = partial('flow-guide');
const styles = partial('operations-styles');
const interactions = partial('operations-interactions');
const detail = read('resources/views/laundry/show.blade.php');
const detailStyles = read('resources/views/laundry/partials/detail-styles.blade.php');
const progress = read('resources/views/components/laundry-progress-tracker.blade.php');
const returnWorkspace = read('resources/views/custody/partials/return-workspace.blade.php');
const script = interactions.match(/<script>([\s\S]*?)<\/script>/)[1];

function element(dataset = {}) {
    const handlers = new Map();
    return {
        dataset,
        value: '',
        textContent: '',
        hidden: false,
        focused: false,
        addEventListener(name, callback) {
            handlers.set(name, [...(handlers.get(name) || []), callback]);
        },
        dispatch(name) { (handlers.get(name) || []).forEach((callback) => callback()); },
        focus() { this.focused = true; },
    };
}

function mount({ paginated = false, recordsPresent = true } = {}) {
    const records = recordsPresent ? [
        element({ search: 'Maria Santos BR-001 Chairs', status: 'FOR_LAUNDRY', date: '100' }),
        element({ search: 'Juan Cruz BR-002 Foundation Linen', status: 'TURNED_OVER_TO_LAUNDRY', date: '300' }),
        element({ search: 'Ana Reyes BR-003 Seminar Linen', status: 'FOR_LAUNDRY', date: '200' }),
    ] : [];
    const list = { children: [...records], appendChild(record) {
        this.children = this.children.filter((child) => child !== record);
        this.children.push(record);
    } };
    const search = element();
    const status = element();
    const sort = element();
    sort.value = 'newest';
    const count = element({ total: paginated ? '47' : String(records.length), paginated: String(paginated) });
    const empty = element();
    const reset = element();
    const controls = {
        '[data-laundry-list]': list,
        '[data-laundry-search]': search,
        '[data-laundry-status]': status,
        '[data-laundry-sort]': sort,
        '[data-laundry-count]': count,
        '[data-laundry-filter-empty]': empty,
        '[data-laundry-reset]': reset,
    };
    const browser = {
        dataset: {},
        querySelector: (selector) => controls[selector],
        querySelectorAll: () => records,
    };
    const context = { document: { querySelector: () => browser } };
    vm.runInNewContext(script, context);
    return { records, list, search, status, sort, count, empty, reset, context };
}

test('the compact tracker appears above both the empty and populated states', () => {
    const include = "@include('laundry.partials.flow-guide')";
    assert.equal(page.split(include).length - 1, 1);
    assert.ok(page.indexOf(include) < page.indexOf('@if($hasLaundryCases)'));
    assert.match(page, /\$hasLaundryCases = \$jobs->total\(\) > 0;/);
    assert.match(page, /@if\(\$hasLaundryCases\)\s+<section class="card laundry-toolbar"/);
    assert.match(page, /@else\s+<section class="card laundry-empty-card"/);
    assert.match(page, /No laundry cases need action\./);
    assert.match(page, /New linen cases will appear here after physical release\./);
});

test('the guide explains the four physical steps without inventing job states', () => {
    assert.equal((guide.match(/class="laundry-flow-number"/g) || []).length, 4);
    for (const label of ['Linen issued', 'Returned to Laundry', 'SPMU records form', 'Clean &amp; available']) {
        assert.ok(guide.includes(`<strong>${label}</strong>`));
    }
    assert.match(guide, /aria-label="Laundry process overview"/);
    assert.match(guide, /aria-describedby="laundry-receipt-guidance"/);
    assert.match(guide, /SPMU uploads the accomplished Laundry Form and records the findings written by Laundry Personnel\./);
    assert.doesNotMatch(guide, /Internal washing|second Laundry turnover confirmation/i);
    assert.doesNotMatch(guide, /aria-current|\$job|data-status|<form|type="submit"/);
});

test('detail view uses the accomplished form as one record instead of tracking wet signatures separately', () => {
    assert.match(detail, /Accomplished & verified/);
    assert.match(detail, /SPMU return:/);
    assert.match(detail, /Serviceable in Laundry:/);
    assert.match(detail, /Laundry processing:/);
    assert.match(detail, /Archive pending/);
    assert.doesNotMatch(detail, /<dt>.*Issued by:|<dt>.*Received by:|Pending return/);
    assert.match(detail, /No further linen action is required from the borrower\./);
    assert.match(detail, /No reclassification is needed here\./);
});

test('detail tracker is monotonic after SPMU return encoding', () => {
    for (const label of ['Laundry Return', 'SPMU Return Encoding', 'Laundry Processing', 'Available']) {
        assert.ok(progress.includes(`'label' => '${label}'`));
    }
    assert.match(progress, /\$returnEncoded = \$inspectionComplete/);
    assert.match(progress, /\$laundryReturnComplete = \$formComplete \|\| \$returnEncoded/);
    assert.doesNotMatch(progress, /Laundry Receipt & Form|Laundry Complete \/ Available/);
    assert.match(detailStyles, /grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/);
});

test('return workspace keeps the linen instructions concise', () => {
    assert.match(returnWorkspace, /Required for linen return\./);
    assert.match(returnWorkspace, /I confirm this is the accomplished Laundry Form signed by Laundry Personnel\./);
    assert.match(returnWorkspace, />Upload Form</);
    assert.match(returnWorkspace, /Linen return completed/);
    assert.match(returnWorkspace, /No further borrower action is required\./);
    assert.match(returnWorkspace, /Open Laundry Processing/);
    assert.doesNotMatch(returnWorkspace, /Open Internal Laundry Processing/);
});

test('flow card stays within the content width and reflows without widening the page', () => {
    const card = styles.match(/\.laundry-operations \.laundry-flow-card \{([\s\S]*?)\}/)[1];
    assert.match(card, /width: 100%;/);
    assert.match(card, /min-width: 0;/);
    assert.match(card, /max-width: 100%;/);
    assert.match(card, /padding: 0;/);
    assert.doesNotMatch(card, /\d+vw|min-width: \d+px|min-height/);
    assert.match(styles, /grid-template-columns: repeat\(4, minmax\(0, 1fr\)\)/);
    assert.match(styles, /@media \(max-width: 700px\)/);
    assert.match(styles, /@media \(max-width: 400px\)/);
    assert.match(styles, /\.laundry-table-wrap \{[^}]*overflow-x: auto/);
});

test('case table preserves live data, current statuses and protected navigation', () => {
    assert.match(page, /route\('laundry\.completed'\)/);
    assert.match(page, /route\('laundry\.show', \$job\)/);
    assert.match(page, /@foreach\(\$jobs as \$job\)/);
    assert.match(page, /\{\{ \$jobs->links\(\) \}\}/);
    assert.match(page, /<x-status-badge :status="\$job->status" :label="\$statusText"/);
    for (const field of ['request_no', 'custody_no', 'full_name', 'unit_name', 'purpose_event', 'received_at', 'description_snapshot']) {
        assert.ok(page.includes(field), `Missing live field: ${field}`);
    }
    assert.match(page, /'FOR_LAUNDRY' => 'Awaiting Laundry Return'/);
    assert.match(page, /'TURNED_OVER_TO_LAUNDRY' => 'Internal Laundry Pending'/);
    assert.doesNotMatch(page, /FOR_SPMU_FINAL_CHECK|AWAITING_FINAL_FORM_UPLOAD|READY_FOR_SPMU_RETURN|For SPMU Acceptance/);
    assert.doesNotMatch(page, /Juan Dela Cruz|CSPC Foundation Day 2026|CUS-2026/);
});

test('filters are read-only and their pagination scope is explicit', () => {
    assert.match(page, /Search, status, and sort apply to the cases on this page\./);
    assert.match(page, /type="button" data-laundry-reset/);
    assert.match(page, /role="status" aria-live="polite"/);
    assert.doesNotMatch(page, /<form|->(?:save|update|create|delete)\(/);
    assert.doesNotMatch(interactions, /fetch\(|XMLHttpRequest|\.submit\(|requestSubmit\(|location\./);
});

test('newest and oldest sorting use the correct date direction', () => {
    const ui = mount();
    assert.deepEqual(ui.list.children.map((row) => row.dataset.date), ['300', '200', '100']);
    ui.sort.value = 'oldest';
    ui.sort.dispatch('change');
    assert.deepEqual(ui.list.children.map((row) => row.dataset.date), ['100', '200', '300']);
    assert.equal(ui.count.textContent, 'Showing 3 of 3 cases');
});

test('search is case-insensitive, matches multiple words and combines with status', () => {
    const ui = mount();
    ui.search.value = '  MARIA   chairs ';
    ui.search.dispatch('input');
    assert.deepEqual(ui.records.map((row) => row.hidden), [false, true, true]);
    assert.equal(ui.count.textContent, 'Showing 1 of 3 cases');
    assert.equal(ui.empty.hidden, true);
    ui.status.value = 'TURNED_OVER_TO_LAUNDRY';
    ui.status.dispatch('change');
    assert.equal(ui.empty.hidden, false);
    assert.ok(ui.records.every((row) => row.hidden));
    ui.search.value = 'linen';
    ui.search.dispatch('input');
    assert.deepEqual(ui.records.map((row) => row.hidden), [true, false, true]);
});

test('clear filters restores the cases and focus without duplicate handlers', () => {
    const ui = mount();
    vm.runInNewContext(script, ui.context);
    ui.search.value = 'no match';
    ui.status.value = 'FOR_LAUNDRY';
    ui.sort.value = 'oldest';
    ui.search.dispatch('input');
    assert.equal(ui.empty.hidden, false);
    ui.reset.dispatch('click');
    assert.equal(ui.search.value, '');
    assert.equal(ui.status.value, '');
    assert.equal(ui.sort.value, 'newest');
    assert.ok(ui.records.every((row) => !row.hidden));
    assert.equal(ui.search.focused, true);
    assert.equal(ui.empty.hidden, true);
});

test('paginated and empty results never claim that hidden cases do not exist', () => {
    const paginated = mount({ paginated: true });
    assert.equal(paginated.count.textContent, 'Showing 3 of 3 cases on this page (47 total)');
    const noRows = mount({ recordsPresent: false, paginated: true });
    assert.equal(noRows.count.textContent, 'Showing 0 of 0 cases on this page (47 total)');
    assert.equal(noRows.empty.hidden, false);
    assert.doesNotThrow(() => vm.runInNewContext(script, {
        document: { querySelector: () => ({ dataset: {}, querySelector: () => null }) },
    }));
});

test('the new Blade branches are balanced and use existing theme variables', () => {
    const source = page.replace(/@php[\s\S]*?@endphp/g, '');
    const stack = [];
    const pairs = { endif: 'if', endforeach: 'foreach', endsection: 'section' };
    for (const [, directive] of source.matchAll(/@(if|endif|foreach|endforeach|section|endsection)\b/g)) {
        if (Object.hasOwn(pairs, directive)) assert.equal(stack.pop(), pairs[directive]);
        else stack.push(directive);
    }
    assert.deepEqual(stack, []);
    const globalStyles = read('public/css/app.css');
    const variables = new Set([...`${globalStyles}\n${styles}`.matchAll(/(--[\w-]+)\s*:/g)].map((match) => match[1]));
    for (const [, name] of styles.matchAll(/var\((--[\w-]+)/g)) {
        assert.ok(variables.has(name), `Undefined CSS variable: ${name}`);
    }
});

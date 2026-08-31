const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../resources/views/gate-passes/partials/index-interactions.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];

function element(dataset = {}) {
    const handlers = new Map();
    return {
        dataset, children: [], attributes: {}, value: '', hidden: false, disabled: false,
        setAttribute(key, value) { this.attributes[key] = value; },
        addEventListener(name, callback) { handlers.set(name, [...(handlers.get(name) || []), callback]); },
        dispatch(name, event) { (handlers.get(name) || []).forEach(callback => callback(event)); },
        appendChild(child) { this.children = this.children.filter(item => item !== child); this.children.push(child); },
        replaceChildren() { this.children = []; },
        querySelector() { return this.children.find(child => child.attributes['aria-current'] === 'page'); },
        querySelectorAll() { return []; },
        closest() { return this.dataset.gatePassPage ? this : null; },
        focus() { this.focused = true; },
    };
}

function mount() {
    const records = Array.from({ length: 23 }, (_, i) => element({
        search: `Borrower ${i + 1} ${i === 0 ? 'Maria Science Hall' : 'Campus'}`,
        status: i % 2 ? 'VERIFIED' : 'PENDING', date: String(i + 1),
    }));
    const names = ['list', 'search', 'status', 'sort', 'empty', 'count', 'pagination', 'page-numbers', 'reset'];
    const controls = Object.fromEntries(names.map(name => [name, element()]));
    controls.sort.value = 'newest';
    controls.list.children = [...records];
    const previous = element({ gatePassPage: 'previous' });
    const next = element({ gatePassPage: 'next' });
    const root = element({ pageSize: '10' });
    root.querySelector = selector => selector.includes('="previous"') ? previous
        : selector.includes('="next"') ? next : controls[selector.slice(16, -1)];
    root.querySelectorAll = () => records;
    const context = { document: { querySelector: () => root, createElement: () => element() } };
    vm.runInNewContext(script, context);
    return { ...controls, previous, next, context, records,
        visible: () => controls.list.children.filter(row => !row.hidden).map(row => Number(row.dataset.date)),
        click: target => controls.pagination.dispatch('click', { target }),
    };
}

test('pagination exposes all records with correct ranges and boundaries', () => {
    const ui = mount();
    assert.deepEqual(ui.visible(), [23, 22, 21, 20, 19, 18, 17, 16, 15, 14]);
    assert.equal(ui.count.textContent, 'Showing 1 to 10 of 23 records');
    assert.equal(ui.previous.disabled, true);
    ui.click(ui.next);
    assert.equal(ui.count.textContent, 'Showing 11 to 20 of 23 records');
    ui.click(ui.next);
    assert.deepEqual(ui.visible(), [3, 2, 1]);
    assert.equal(ui.next.disabled, true);
    ui.click(ui.next);
    assert.equal(ui.count.textContent, 'Showing 21 to 23 of 23 records');
    ui.click(ui.previous);
    assert.equal(ui.count.textContent, 'Showing 11 to 20 of 23 records');
    assert.equal(ui['page-numbers'].querySelector().focused, true);
});

test('search finds records outside the current page and combines with status', () => {
    const ui = mount();
    ui.search.value = '  SCIENCE   maria ';
    ui.search.dispatch('input');
    assert.deepEqual(ui.visible(), [1]);
    assert.equal(ui.count.textContent, 'Showing 1 to 1 of 1 records');
    ui.status.value = 'VERIFIED';
    ui.status.dispatch('change');
    assert.deepEqual(ui.visible(), []);
    assert.equal(ui.empty.hidden, false);
    assert.equal(ui.pagination.hidden, true);
    assert.equal(ui.count.textContent, 'Showing 0 records');
    ui.reset.dispatch('click');
    assert.equal(ui.empty.hidden, true);
    assert.equal(ui.pagination.hidden, false);
    assert.equal(ui.search.focused, true);
    assert.equal(ui.visible()[0], 23);
});

test('sort and filter changes reset pagination, while numbered navigation works', () => {
    const ui = mount();
    ui.click(ui['page-numbers'].children.find(button => button.dataset.gatePassPage === '3'));
    assert.equal(ui.count.textContent, 'Showing 21 to 23 of 23 records');
    ui.sort.value = 'oldest';
    ui.sort.dispatch('change');
    assert.deepEqual(ui.visible(), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
    ui.status.value = 'VERIFIED';
    ui.status.dispatch('change');
    assert.equal(ui.count.textContent, 'Showing 1 to 10 of 11 records');
    ui.click(ui.next);
    assert.deepEqual(ui.visible(), [22]);
});

test('reinitializing is harmless and the true empty state needs no controls', () => {
    const ui = mount();
    vm.runInNewContext(script, ui.context);
    ui.click(ui.next);
    assert.equal(ui.count.textContent, 'Showing 11 to 20 of 23 records');
    assert.doesNotThrow(() => vm.runInNewContext(script, {
        document: { querySelector: () => ({ dataset: {}, querySelector: () => null }) },
    }));
});

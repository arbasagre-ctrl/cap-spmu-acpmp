const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../resources/views/requests/form.blade.php'), 'utf8');
const names = ['isSelected', 'getSelectedRows', 'isOffCampusMode', 'syncRequestPremises', 'syncCatalogButtons', 'handlePremisesChange'];
const logic = names.map(name => {
    const match = source.match(new RegExp('^    function ' + name + '\\([\\s\\S]*?^    }', 'm'));
    assert.ok(match, name + ' must exist in the request form');
    return match[0];
}).join('\n');
const listeners = source.match(/^    request(?:Off|On)CampusToggle\?\.addEventListener\('change', handlePremisesChange\);/gm).join('\n');

function radio(checked) {
    const events = {};
    return { checked, addEventListener: (name, callback) => { events[name] = callback; }, change: () => events.change() };
}

function mount({ selected = ['1'], offCampus = false, accept = true } = {}) {
    const rows = ['1', '2', '3'].map(id => ({ dataset: { selectedItem: id, offCampusAllowed: id === '1' ? '0' : '1' } }));
    const checkboxes = Object.fromEntries(rows.map(row => [row.dataset.selectedItem, { checked: selected.includes(row.dataset.selectedItem) }]));
    const quantities = Object.fromEntries(rows.map(row => [row.dataset.selectedItem, { value: checkboxes[row.dataset.selectedItem].checked ? '4' : '0' }]));
    const locations = Object.fromEntries(rows.map(row => [row.dataset.selectedItem, { value: offCampus && checkboxes[row.dataset.selectedItem].checked ? 'OFF_CAMPUS' : 'ON_CAMPUS' }]));
    const buttons = rows.map(row => ({ dataset: { addItem: row.dataset.selectedItem }, classList: { add() {}, remove() {} } }));
    const on = radio(!offCampus), off = radio(offCampus);
    let prompts = 0;
    const context = {
        requestOnCampusToggle: on, requestOffCampusToggle: off,
        premisesHelp: {}, searchInput: {}, availabilityLoaded: true,
        scheduleDate: { value: '2026-09-01' }, returnDate: { value: '2026-09-02' },
        availability: { 1: { available: 20 }, 2: { available: 10 }, 3: { available: 10 } },
        document: {
            querySelectorAll(selector) {
                return selector === '[data-selected-item]' ? rows
                    : selector === '[data-selected-location]' ? Object.values(locations)
                    : selector === '[data-add-item]' ? buttons : [];
            },
            querySelector(selector) {
                const id = selector.match(/="(\d+)"/)?.[1];
                if (selector.startsWith('[data-selected-checkbox=')) return checkboxes[id];
                if (selector.startsWith('[data-selected-quantity=')) return quantities[id];
                if (selector.startsWith('[data-selected-location=')) return locations[id];
                if (selector.startsWith('[data-catalog-item]')) return rows.find(row => row.dataset.selectedItem === id);
                return null;
            },
        },
        window: { confirm() { prompts++; return accept; } },
        syncSelectedItems() {},
        renderCatalog() { context.syncCatalogButtons(); },
    };
    vm.runInNewContext(logic + '\n' + listeners, context);
    context.syncRequestPremises();
    context.syncCatalogButtons();
    return {
        context, on, off, checkboxes, quantities, locations, buttons,
        prompts: () => prompts,
        chooseOff(enabled) { off.checked = enabled; on.checked = !enabled; (enabled ? off : on).change(); },
    };
}

test('switching to On-campus keeps selected quantities and updates submitted locations', () => {
    const ui = mount({ selected: ['2'], offCampus: true });
    ui.chooseOff(false);
    assert.equal(ui.on.checked, true);
    assert.equal(ui.off.checked, false);
    assert.equal(ui.locations['2'].value, 'ON_CAMPUS');
    assert.equal(ui.checkboxes['2'].checked, true);
    assert.equal(ui.quantities['2'].value, '4');
    assert.equal(ui.buttons[0].disabled, false);
    assert.equal(ui.prompts(), 0);
});

test('canceling an incompatible Off-campus switch restores the radio without losing items', () => {
    const ui = mount({ selected: ['1', '2'], accept: false });
    ui.chooseOff(true);
    assert.equal(ui.prompts(), 1);
    assert.equal(ui.on.checked, true);
    assert.equal(ui.off.checked, false);
    for (const id of ['1', '2']) {
        assert.equal(ui.checkboxes[id].checked, true);
        assert.equal(ui.quantities[id].value, '4');
        assert.equal(ui.locations[id].value, 'ON_CAMPUS');
    }
    assert.match(ui.context.searchInput.placeholder, /item name/);
});

test('confirming an incompatible switch clears quantities and only enables eligible items', () => {
    const ui = mount({ selected: ['1', '2'] });
    ui.chooseOff(true);
    assert.equal(ui.off.checked, true);
    assert.equal(ui.on.checked, false);
    for (const id of ['1', '2']) {
        assert.equal(ui.checkboxes[id].checked, false);
        assert.equal(ui.quantities[id].value, '0');
        assert.equal(ui.locations[id].value, 'ON_CAMPUS');
    }
    assert.equal(ui.buttons[0].disabled, true);
    assert.equal(ui.buttons[1].disabled, false);
    assert.match(ui.context.searchInput.placeholder, /Barricade/);
});

test('a sole eligible item stays selected Off-campus and prevents adding another', () => {
    const ui = mount({ selected: ['2'] });
    ui.chooseOff(true);
    assert.equal(ui.prompts(), 0);
    assert.equal(ui.quantities['2'].value, '4');
    assert.equal(ui.locations['2'].value, 'OFF_CAMPUS');
    assert.equal(ui.locations['3'].value, 'ON_CAMPUS');
    assert.equal(ui.buttons.every(button => button.disabled), true);
    ui.chooseOff(false);
    assert.equal(ui.locations['2'].value, 'ON_CAMPUS');
    assert.equal(ui.buttons[2].disabled, false);
});

test('multiple eligible items still require confirmation before switching Off-campus', () => {
    const ui = mount({ selected: ['2', '3'], accept: false });
    ui.chooseOff(true);
    assert.equal(ui.prompts(), 1);
    assert.equal(ui.on.checked, true);
    assert.equal(ui.checkboxes['2'].checked, true);
    assert.equal(ui.checkboxes['3'].checked, true);
});

test('changing premises never bypasses date or availability restrictions', () => {
    const ui = mount({ selected: [] });
    ui.context.availabilityLoaded = false;
    ui.chooseOff(true);
    assert.equal(ui.buttons.every(button => button.disabled), true);
    ui.chooseOff(false);
    assert.equal(ui.buttons.every(button => button.disabled), true);
    ui.context.availabilityLoaded = true;
    ui.context.scheduleDate.value = '';
    ui.chooseOff(true);
    assert.equal(ui.buttons.every(button => button.disabled), true);
});

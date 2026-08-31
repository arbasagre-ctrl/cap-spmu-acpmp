const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../resources/views/custody/partials/return-process-interactions.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
const conditions = ['FINE', 'DAMAGED', 'DESTROYED', 'MISSING', 'LOST', 'STOLEN'];

function element(properties = {}) {
    const listeners = new Map();
    const classes = new Set();
    return {
        dataset: {}, textContent: '', hidden: false, required: false,
        querySelector: () => null,
        addEventListener(name, callback) {
            listeners.set(name, [...(listeners.get(name) || []), callback]);
        },
        dispatch(name) { (listeners.get(name) || []).forEach(callback => callback()); },
        listenerCount(name) { return (listeners.get(name) || []).length; },
        classList: {
            toggle(name, present) { present ? classes.add(name) : classes.delete(name); },
            contains(name) { return classes.has(name); },
        },
        ...properties,
    };
}

function inspection(outstandings = [100, 5], hasForm = true) {
    const rows = outstandings.map(outstanding => {
        const inputs = Object.fromEntries(conditions.map(condition => {
            const input = element({ value: '0', dataset: { condition } });
            Object.defineProperty(input, 'validity', {
                get() {
                    const value = Number(input.value);
                    return { valid: input.value === '' || (Number.isInteger(value) && value >= 0 && value <= outstanding) };
                },
            });
            return [condition, input];
        }));
        const total = element();
        const state = element();
        const evidence = element();
        const police = element();
        const policeWrap = element();
        const details = element({
            matches: selector => selector === '[data-return-issue-details]',
            querySelector: selector => ({ '.return-evidence-input': evidence, '.return-police-input': police, '[data-police-wrap]': policeWrap })[selector],
        });
        return element({
            dataset: { outstanding: String(outstanding) },
            inputs, total, state, evidence, police, policeWrap, details,
            nextElementSibling: details,
            querySelectorAll: () => Object.values(inputs),
            querySelector: selector => ({ '.return-accounted-total': total, '.return-accounted-state': state, '[data-condition="STOLEN"]': inputs.STOLEN })[selector],
        });
    });
    const copy = element();
    const warningIcon = element();
    const successIcon = element();
    const message = element({ querySelector: selector => ({ '[data-return-accounting-copy]': copy, '[data-return-accounting-warning]': warningIcon, '[data-return-accounting-success]': successIcon })[selector] });
    const button = element({ disabled: true });
    const remarks = element({ value: 'Saved inspection note' });
    const count = element();
    const dismiss = element();
    const flash = element({ querySelector: () => dismiss });
    const form = element({
        querySelectorAll: () => rows,
        querySelector: selector => ({ 'textarea[name="remarks"]': remarks, '[data-return-remarks-count]': count })[selector],
    });
    const context = {
        document: {
            querySelector: () => flash,
            getElementById: id => ({ 'full-return-accounting-form': hasForm ? form : null, 'record-return-button': button, 'return-accounting-message': message })[id],
        },
    };
    const run = () => vm.runInNewContext(script, context);
    run();
    return {
        rows, form, button, message, copy, warningIcon, successIcon, remarks, count, flash, dismiss, run,
        quantity(row, condition, value) {
            rows[row].inputs[condition].value = String(value);
            rows[row].inputs[condition].dispatch('input');
        },
    };
}

test('a return requires full accounting for every selected item, while untouched items can wait', () => {
    const ui = inspection();
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.rows[0].total.textContent, '0 / 100');
    assert.equal(ui.rows[0].state.textContent, '0% accounted');
    ui.quantity(0, 'FINE', 99);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.rows[0].state.textContent, '99% accounted');
    ui.quantity(0, 'FINE', 100);
    assert.equal(ui.button.disabled, false);
    ui.quantity(1, 'FINE', 3);
    assert.equal(ui.button.disabled, true);
    ui.quantity(1, 'FINE', 5);
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.warningIcon.hidden, true);
    assert.equal(ui.successIcon.hidden, false);
    assert.match(ui.copy.textContent, /fully accounted/);
});

test('mixed conditions require evidence, and stolen quantities also require a police reference', () => {
    const ui = inspection();
    ui.quantity(0, 'FINE', 80);
    ui.quantity(0, 'DAMAGED', 20);
    assert.equal(ui.button.disabled, false);
    assert.equal(ui.rows[0].details.hidden, false);
    assert.equal(ui.rows[0].evidence.required, true);
    assert.equal(ui.rows[0].police.required, false);
    ui.quantity(0, 'FINE', 79);
    ui.quantity(0, 'STOLEN', 1);
    assert.equal(ui.rows[0].police.required, true);
    assert.equal(ui.rows[0].policeWrap.hidden, false);
    ui.quantity(0, 'STOLEN', 0);
    ui.quantity(0, 'DAMAGED', 0);
    ui.quantity(0, 'FINE', 100);
    assert.equal(ui.rows[0].details.hidden, true);
    assert.equal(ui.rows[0].evidence.required, false);
    assert.equal(ui.rows[0].police.required, false);
});

test('over-counted, negative, fractional, and invalid quantities cannot enable recording', () => {
    const ui = inspection();
    ui.quantity(0, 'FINE', 100);
    ui.quantity(1, 'FINE', -1);
    assert.equal(ui.button.disabled, true);
    ui.quantity(1, 'FINE', 0);
    ui.quantity(0, 'FINE', 99.5);
    ui.quantity(0, 'DAMAGED', 0.5);
    assert.equal(ui.button.disabled, true);
    ui.quantity(0, 'DAMAGED', 0);
    ui.quantity(0, 'FINE', 101);
    assert.equal(ui.button.disabled, true);
    assert.equal(ui.rows[0].state.textContent, '101% accounted');
    ui.quantity(0, 'FINE', 'invalid');
    assert.equal(ui.button.disabled, true);
});

test('incomplete large quantities are never rounded up to 100% accounted', () => {
    const ui = inspection([1000]);
    ui.quantity(0, 'FINE', 999);
    assert.equal(ui.rows[0].state.textContent, '99% accounted');
    assert.equal(ui.button.disabled, true);
    ui.quantity(0, 'FINE', 1000);
    assert.equal(ui.rows[0].state.textContent, '100% accounted');
    assert.equal(ui.button.disabled, false);
});

test('remarks count includes restored input and updates without changing workflow data', () => {
    const ui = inspection();
    assert.equal(ui.count.textContent, String(ui.remarks.value.length));
    ui.remarks.value = 'Two table cloths stained.';
    ui.remarks.dispatch('input');
    assert.equal(ui.count.textContent, String(ui.remarks.value.length));
    assert.equal(ui.button.disabled, true);
    ui.run();
    assert.equal(ui.remarks.listenerCount('input'), 1);
    assert.equal(ui.rows[0].inputs.FINE.listenerCount('input'), 1);
});

test('success notice can be dismissed even when no items need inspection', () => {
    const ui = inspection([], false);
    ui.run();
    assert.equal(ui.dismiss.listenerCount('click'), 1);
    ui.dismiss.dispatch('click');
    assert.equal(ui.flash.hidden, true);
});

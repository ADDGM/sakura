const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const {test} = require('node:test');

const source = fs.readFileSync(require('node:path').join(__dirname, '../../user/verification.js'), 'utf8');

function field() {
    const form = new EventTarget();
    const range = new EventTarget();
    range.focus = () => { range.focused = true; };
    const marker = {disabled: true};
    const label = {textContent: 'Cached OK'};
    const node = {
        dataset: {prompt: 'Drag to verify'},
        style: {setProperty(name, value) { this[name] = value; }},
        querySelector(selector) {
            return selector.includes('range') ? range : selector.includes('verification"]') ? marker : label;
        },
        closest() { return form; }
    };
    return {node, form, range, marker, label};
}

function setup(fields = [field()], readyState = 'complete') {
    const document = new EventTarget();
    document.readyState = readyState;
    document.querySelectorAll = () => fields.map(item => item.node);
    const window = {};
    vm.runInNewContext(source, {window, document, WeakSet});
    return {fields, window, document};
}

function submit(form) {
    return form.dispatchEvent(new Event('submit', {cancelable: true}));
}

function input(range, value) {
    range.value = String(value);
    range.dispatchEvent(new Event('input'));
}

test('unfinished verification blocks only its own form and preserves other listeners', () => {
    const {fields: [item]} = setup();
    let observed = 0;
    item.form.addEventListener('submit', () => observed++);
    assert.equal(submit(item.form), false);
    assert.equal(observed, 1);
    assert.equal(item.range.focused, true);
    assert.equal(submit(new EventTarget()), true);
    assert.equal(item.marker.disabled, false);
});

test('completion omits the existing server marker and allows submitting only that form', () => {
    const {fields: [first, second]} = setup([field(), field()]);
    input(first.range, 100);
    assert.equal(first.marker.disabled, true);
    assert.equal(first.range.disabled, true);
    assert.equal(first.label.textContent, 'OK!');
    assert.equal(submit(first.form), true);
    assert.equal(submit(second.form), false);
});

test('partial pointer release, cancellation and blur reset without unlocking', () => {
    const {fields: [item]} = setup();
    for (const event of ['pointerup', 'pointercancel', 'blur']) {
        input(item.range, 65);
        item.range.dispatchEvent(new Event(event));
        assert.equal(item.range.value, '0');
        assert.equal(item.node.style['--sakura-verification-progress'], '0%');
        assert.equal(submit(item.form), false);
    }
});

test('keyboard input can accumulate and completion survives blur and repeated initialization', () => {
    const {fields: [item], window} = setup();
    input(item.range, 1);
    input(item.range, 2);
    assert.equal(item.range.value, '2');
    input(item.range, 100);
    item.range.dispatchEvent(new Event('blur'));
    window.SakuraVerification.init();
    assert.equal(submit(item.form), true);
    assert.equal(item.marker.disabled, true);
});

test('new PJAX nodes reset cached disabled state and receive exactly one submit listener', () => {
    const {fields, window} = setup();
    const next = field();
    fields.splice(0, 1, next);
    let listeners = 0;
    const add = next.form.addEventListener.bind(next.form);
    next.form.addEventListener = (...args) => { listeners++; add(...args); };
    window.SakuraVerification.init();
    window.SakuraVerification.init();
    assert.equal(listeners, 1);
    assert.equal(next.marker.disabled, false);
    assert.equal(next.range.disabled, false);
    assert.equal(next.label.textContent, 'Drag to verify');
    assert.equal(submit(next.form), false);
});

test('initialization waits for the document and skips pages without a verification field', () => {
    const {fields: [item], document} = setup([field()], 'loading');
    assert.equal(submit(item.form), true);
    document.dispatchEvent(new Event('DOMContentLoaded'));
    assert.equal(submit(item.form), false);
    assert.doesNotThrow(() => setup([]));
});

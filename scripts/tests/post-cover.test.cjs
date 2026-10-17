const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../inc/js/post-cover.js'), 'utf8');
const names = ['type', 'video', 'poster'];
const hash = value => 'fingerprint:' + value;

// 只替代 DOM 与宿主 store；执行完整生产脚本和真实 Response/FormData。
function fixture(values = {}, block = true) {
    const initial = {type: 'hls', video: 'https://example.com/a.m3u8', poster: '', ...values};
    const cells = new Map();
    function cell(selector) {
        if (!cells.has(selector)) cells.set(selector, {
            value: '', attrs: {}, props: {}, handlers: {},
            val(value) { if (arguments.length) { this.value = value; return this; } return this.value; },
            attr(key, value) { if (arguments.length === 2) { this.attrs[key] = value; return this; } return this.attrs[key]; },
            prop(key, value) { this.props[key] = value; return this; },
            removeAttr(key) { delete this.attrs[key]; return this; },
            on(events, handler) { for (const event of events.split(' ')) this.handlers[event] = handler; return this; },
            trigger(event) { this.handlers[event]?.({preventDefault() {}}); return this; },
            closest() { return cell('form'); },
            find: cell,
            each(callback) { callback.call(this); },
        });
        return cells.get(selector);
    }
    const box = cell('box');
    box.attr('data-block-editor', block ? '1' : '0');
    names.forEach(name => {
        cell('#sakura-cover-' + name).val(initial[name]);
        cell('[name="sakura_cover_original[' + name + ']"]').val(hash(initial[name]));
    });
    const $ = arg => {
        if (typeof arg === 'function') return arg();
        if (arg === '#sakura-post-cover .sakura-cover-fields') return box;
        return arg;
    };
    let middleware;
    const locks = new Set();
    const editor = {lockPostSaving: key => locks.add(key), unlockPostSaving: key => locks.delete(key)};
    class DOMParser {
        parseFromString(text) {
            // 用已解析的响应面板替代浏览器HTML解析，真实HTML由WordPress验收覆盖。
            const saved = JSON.parse(text);
            return {querySelector: () => saved.missing ? null : {
                getAttribute: () => saved.error || '',
                querySelector: selector => {
                    const name = selector.match(/\[(type|video|poster)\]/)[1];
                    if (!(name in saved.values)) return null;
                    return {value: selector.includes('_original') ? hash(saved.values[name]) : saved.values[name]};
                },
            }};
        }
    }
    const url = 'http://localhost/wp-admin/post.php?meta-box-loader=1';
    vm.runInNewContext(source, {jQuery: $, URL, DOMParser, window: {_wpMetaBoxUrl: url},
        wp: {apiFetch: {use: fn => {middleware = fn;}}, data: {dispatch: () => editor}}});
    const fields = Object.fromEntries(names.map(name => [name, cell('#sakura-cover-' + name)]));
    return {
        cell, fields, locks, middleware,
        change(name, value) { fields[name].val(value).trigger(name === 'type' ? 'change' : 'input'); },
        payload() {
            const body = new FormData();
            body.set('sakura_cover_nonce', 'test-nonce');
            for (const name of names) {
                body.set('sakura_cover[' + name + ']', fields[name].val());
                body.set('sakura_cover_original[' + name + ']', cell('[name="sakura_cover_original[' + name + ']"]').val());
            }
            return {url, method: 'POST', body};
        },
    };
}

function response(values, error = '') { return new Response(JSON.stringify({values, error})); }
function backend(saved) {
    return async options => {
        for (const name of names) {
            const value = options.body.get('sakura_cover[' + name + ']');
            if (hash(value) !== options.body.get('sakura_cover_original[' + name + ']')) saved[name] = value.trim();
        }
        return response(saved);
    };
}

test('同页 A→B→A 更新三个字段，未操作字段不覆盖其他入口', async () => {
    const f = fixture();
    const saved = Object.fromEntries(names.map(name => [name, f.fields[name].val()]));
    const original = {...saved};
    f.change('type', 'normal');
    f.change('video', 'https://example.com/b.webm');
    f.change('poster', 'https://example.com/b.jpg');
    const first = await f.middleware(f.payload(), backend(saved));
    assert.equal((await first.json()).values.type, 'normal', '响应副本不消耗调用方body');
    for (const name of names) f.change(name, original[name]);
    await f.middleware(f.payload(), backend(saved));
    assert.deepEqual(saved, original);
    saved.poster = 'https://example.com/custom-field.jpg';
    await f.middleware(f.payload(), backend(saved));
    assert.equal(saved.poster, 'https://example.com/custom-field.jpg');
    assert.equal(f.fields.poster.val(), saved.poster);
});

test('保存中的新输入保留，比较基准只使用已保存的响应值', async () => {
    const f = fixture();
    f.change('video', '  https://example.com/b.mp4  ');
    let complete;
    const pending = f.middleware(f.payload(), () => new Promise(resolve => { complete = resolve; }));
    f.change('video', 'https://example.com/c.mp4');
    complete(response({type: 'hls', video: 'https://example.com/b.mp4', poster: ''}));
    await pending;
    assert.equal(f.fields.video.val(), 'https://example.com/c.mp4');
    assert.equal(f.payload().body.get('sakura_cover_original[video]'), hash('https://example.com/b.mp4'));
    const saved = {type: 'hls', video: 'https://example.com/b.mp4', poster: ''};
    await f.middleware(f.payload(), backend(saved));
    assert.equal(saved.video, 'https://example.com/c.mp4');
});

test('规范化已保存的值；旧非法值修好后再次输入会被校验', async () => {
    const f = fixture({video: 'legacy-invalid'});
    assert.equal(f.locks.size, 0);
    f.change('video', ' https://example.com/fixed.mp4 ');
    await f.middleware(f.payload(), backend({type: 'hls', video: 'legacy-invalid', poster: ''}));
    assert.equal(f.fields.video.val(), 'https://example.com/fixed.mp4');
    f.change('video', 'legacy-invalid');
    assert.ok(f.locks.has('sakura-post-cover'));
    f.change('video', 'https://example.com/fixed.mp4');
    assert.equal(f.locks.size, 0);
});

test('只处理本面板的meta box POST，其他请求直接透传', async () => {
    const f = fixture();
    for (const options of [{url: '/wp-json/x', method: 'POST'}, {...f.payload(), method: 'GET'},
        {...f.payload(), body: new FormData()}, {...f.payload(), body: {}}]) {
        const result = {};
        assert.equal(await f.middleware(options, actual => { assert.equal(actual, options); return result; }), result);
    }
    assert.equal(fixture({}, false).middleware, undefined);
});

test('网络失败不推进基准，并显示重载提示与保存锁', async () => {
    const f = fixture();
    const before = f.payload().body.get('sakura_cover_original[type]');
    f.change('type', 'normal');
    await assert.rejects(f.middleware(f.payload(), () => Promise.reject(new Error('offline'))), /offline/);
    assert.equal(f.payload().body.get('sakura_cover_original[type]'), before);
    assert.ok(f.locks.has('sakura-post-cover-sync'));
    assert.equal(f.cell('.sakura-cover-save-error').props.hidden, false);
});

test('登录页、缺字段、解析失败和HTTP失败均不能当作已保存', async () => {
    const consumed = response({type: 'normal', video: '', poster: ''});
    await consumed.text();
    for (const reply of [new Response('{"missing":true}'), response({type: 'normal'}),
        new Response('<html>login</html>'), new Response('{}', {status: 500}), consumed]) {
        const f = fixture();
        f.change('type', 'normal');
        await f.middleware(f.payload(), () => Promise.resolve(reply));
        assert.equal(f.payload().body.get('sakura_cover_original[type]'), hash('hls'));
        assert.ok(f.locks.has('sakura-post-cover-sync'));
    }
});

test('服务端拒绝或部分写入时显示错误、同步实际值并保留待修输入', async () => {
    for (const error of ['invalid', 'save']) {
        const f = fixture();
        f.change('type', 'normal');
        f.change('poster', 'https://example.com/retry.jpg');
        const saved = {type: error === 'save' ? 'normal' : 'hls', video: f.fields.video.val(), poster: ''};
        await f.middleware(f.payload(), () => Promise.resolve(response(saved, error)));
        assert.equal(f.fields.poster.val(), 'https://example.com/retry.jpg');
        assert.equal(f.payload().body.get('sakura_cover_original[type]'), hash(saved.type));
        assert.equal(f.cell(error === 'save' ? '.sakura-cover-save-error' : '.sakura-cover-error').props.hidden, false);
        await f.middleware(f.payload(), backend(saved));
        assert.equal(saved.poster, 'https://example.com/retry.jpg');
        assert.equal(f.cell('.sakura-cover-save-error').props.hidden, true);
    }
});

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const {createHash} = require('node:crypto');
const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const model = {id: 'pio', sha256: createHash('sha256').update(fs.readFileSync(path.join(root, 'live2d/model/pio/model.moc'))).digest('hex')};
const url = 'https://example.test/uploads/pio/v1/catalog.json';
const pack = () => ({version: 1, packVersion: 'v1', model, costumes: [{id: 'one', texture: 'textures/one.png'}]});

function fixture() {
    const requests = [], merges = [], warnings = [];
    let current = true;
    const api = {mergeCostumes: (urls, revision) => {merges.push({urls: Array.from(urls), revision}); return true;}};
    class XHR {
        constructor() {requests.push(this);}
        open(method, value, async) {Object.assign(this, {method, url: value, async});}
        send() {}
        abort() {this.aborted = true; if (this.onabort) this.onabort();}
        respond(data, status = 200, responseURL = this.url) {
            Object.assign(this, {status, responseURL, responseText: typeof data === 'string' ? data : JSON.stringify(data)});
            if (this.onload) this.onload();
        }
    }
    const window = {sakuraLive2D: api, location: {protocol: 'https:'}, XMLHttpRequest: XHR, Blob, console: {warn: text => warnings.push(text)}};
    const context = vm.createContext({window, URL});
    const rerun = () => vm.runInContext(read('live2d/js/catalog.js'), context);
    rerun();
    return {requests, merges, warnings, api, rerun, stale: () => {current = false;},
        load: (options = {}, revision = 1) => api.loadCostumeCatalog({url, model, ...options}, revision, () => current)};
}

test('valid catalogs resolve relative paths without cache busting or preloading images', async () => {
    const f = fixture(), p = f.load();
    const data = pack(); data.costumes.push({...data.costumes[0]}, {id: 'alias', texture: 'textures/one.png'});
    f.requests[0].respond(data);
    assert.equal(await p, true);
    assert.deepEqual(f.merges, [{urls: ['https://example.test/uploads/pio/v1/textures/one.png'], revision: 1}]);
    assert.equal(f.requests.length, 1);
    assert.equal(f.requests[0].timeout, 10000);
    assert.equal(f.requests[0].withCredentials, undefined);
    f.rerun(); assert.equal(f.load(), p); assert.equal(f.requests.length, 1);
});

for (const [name, change] of [
    ['unknown format', p => {p.version = 2;}],
    ['wrong model', p => {p.model = {...model, id: 'tia'};}],
    ['wrong model hash', p => {p.model = {...model, sha256: '0'.repeat(64)};}],
    ['missing pack version', p => {delete p.packVersion;}],
    ['conflicting ID', p => {p.costumes.push({id: 'one', texture: 'two.png'});}],
    ['too many costumes', p => {p.costumes = Array(257).fill(p.costumes[0]);}],
    ...['../one.png', 'textures/../one.png', '/one.png', '//other.test/a.png', 'https://other.test/a.png',
        '%2e%2e/a.png', 'textures\\a.png', 'a.png?q=1', 'a.png#hash', '.hidden/a.png', 'a.svg', 'a//b.png'].map(texture => [texture, p => {p.costumes[0].texture = texture;}])
]) {
    test('catalog rejects ' + name + ' without partially merging', async () => {
        const f = fixture(), p = f.load(), data = pack(); change(data);
        f.requests[0].respond(data); assert.equal(await p, false); assert.equal(f.merges.length, 0);
    });
}

test('empty catalog adds no candidates', async () => {
    const f = fixture(), p = f.load(), data = pack(); data.costumes = [];
    f.requests[0].respond(data); assert.equal(await p, true); assert.deepEqual(f.merges[0].urls, []);
});

for (const value of ['javascript:alert(1)', '//example.test/a.json', '/a.json', 'https://user:pass@example.test/a.json',
    'https://example.test/a.png', 'https://example.test/a.json#x', 'http://example.test/a.json']) {
    test('invalid catalog address makes no request: ' + value, async () => {
        const f = fixture(); assert.equal(await f.load({url: value}), false); assert.equal(f.requests.length, 0);
    });
}

test('404, redirects, malformed JSON, network errors and timeout preserve the base pool', async () => {
    for (const respond of [r => r.respond(pack(), 404), r => r.respond(pack(), 200, 'https://other.test/catalog.json'),
        r => r.respond('{'), r => r.onerror(), r => r.ontimeout()]) {
        const f = fixture(), p = f.load(); respond(f.requests[0]);
        assert.equal(await p, false); assert.equal(f.merges.length, 0);
    }
});

test('catalog byte cap rejects oversized responses and aborts during transfer', async () => {
    const f = fixture(), p = f.load(); f.requests[0].respond(' '.repeat(65537));
    assert.equal(await p, false);
    const g = fixture(), q = g.load(); g.requests[0].onprogress({loaded: 65537});
    assert.equal(await q, false); assert.equal(g.requests[0].aborted, true); assert.equal(g.merges.length, 0);
});

test('late and superseded responses cannot add costumes to a new configuration', async () => {
    const f = fixture(), p = f.load(); f.stale(); f.requests[0].respond(pack());
    assert.equal(await p, false); assert.equal(f.merges.length, 0);
    const g = fixture(), old = g.load(), late = g.requests[0].onload;
    const next = g.load({url: 'https://example.test/uploads/pio/v2/catalog.json'}, 2);
    assert.equal(await old, false); assert.equal(g.requests[0].aborted, true);
    late(); assert.equal(g.merges.length, 0);
    g.requests[1].respond(pack()); assert.equal(await next, true); assert.equal(g.merges[0].revision, 2);
});

test('loader starts the model before a catalog and repeated initialization is idempotent', () => {
    const requests = [], events = [];
    const api = {configureCostumes: () => 7, loadCostumeCatalog: (options, revision, current) => {events.push('catalog'); assert.equal(revision, 7); assert.equal(current(), true);}};
    const config = {enabled: true, modelPath: '/pio/', catalogUrl: url, catalogModel: model};
    const window = {SakuraLive2D: config, sakuraLive2D: api, matchMedia: () => ({matches: true}),
        loadlive2d: () => events.push('model'), jQuery: {getJSON(url) {
            const r = {url, done(fn) {this.success = fn; return this;}, fail(fn) {this.error = fn; return this;}};
            requests.push(r); return r;
        }}};
    const context = vm.createContext({window});
    const run = () => vm.runInContext(read('live2d/js/run_local.js'), context);
    run(); run(); assert.equal(requests.length, 1);
    requests[0].success({model: 'model.moc'});
    requests[1].success({pioUrl: [{a: 'a.png'}, {b: 'b.png'}]});
    assert.deepEqual(events, ['model', 'catalog']); run(); assert.equal(requests.length, 2);
    window.SakuraLive2D = {...config, modelPath: '/tia/'}; run();
    const old = requests[2]; window.SakuraLive2D = {...config, modelPath: '/new/'}; run();
    old.success({}); assert.equal(requests.length, 4);
    requests[3].success({}); window.SakuraLive2D.enabled = false;
    requests[4].success({pioUrl: [{a: 'a.png'}]}); assert.deepEqual(events, ['model', 'catalog']);
});

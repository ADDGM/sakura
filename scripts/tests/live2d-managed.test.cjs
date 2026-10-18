const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const {buildCatalog} = require('../build-live2d-costumes.cjs');
const root = path.resolve(__dirname, '../..');
const catalog = JSON.parse(fs.readFileSync(path.join(root, 'live2d/costumes/pio/catalog.json')));

test('model library is reproducible, deduplicated, and uses safe relative paths', () => {
    const actual = buildCatalog('pio');
    assert.deepEqual(actual.catalog, catalog);
    assert.equal(actual.duplicates, 3);
    assert.equal(catalog.costumes.length, 104);
    assert.equal(catalog.costumes.reduce((sum, item) => sum + item.bytes, 0), 50456340);
    assert.ok(catalog.costumes.every(item => /^textures\/[a-z0-9-]+\.png$/.test(item.texture)));
    assert.equal(new Set(catalog.costumes.map(item => item.sha256)).size, 104);
    assert.equal(fs.existsSync(path.join(root, 'live2d/textures.json')), false);
});

test('managed URLs merge after one bundled initial texture, without fetching PNGs', () => {
    const requests = [], loads = [], merges = [];
    let generation = 0;
    const config = {enabled: true, modelPath: '/pio/', costumeUrls: catalog.costumes.map(item => 'https://cdn.example.test/' + item.texture)};
    const api = {configureCostumes: () => ++generation, mergeCostumes: (urls, revision) => merges.push({urls, revision})};
    const window = {SakuraLive2D: config, sakuraLive2D: api, matchMedia: () => ({matches: true}), loadlive2d: (...args) => loads.push(args),
        jQuery: {getJSON(url) {
            requests.push(url);
            return {done(callback) { callback(url.endsWith('model.json') ? {textures: ['default.png']} : {pioUrl: [{one: 'one.png'}, {two: 'two.png'}]}); return this; }, fail() {return this;}};
        }}};
    const loader = fs.readFileSync(path.join(root, 'live2d/js/run_local.js'), 'utf8');
    const context = vm.createContext({window});
    vm.runInContext(loader, context);
    assert.deepEqual(requests, ['/pio/model.json', '/pio/textures.json']);
    assert.equal(loads.length, 1);
    assert.match(loads[0][3].textures[0], /^\/pio\/(one|two)\.png$/);
    assert.equal(merges[0].urls.length, 104);
    vm.runInContext(loader, context);
    assert.equal(loads.length, 1, 'same configuration is idempotent');
    config.costumeUrls = ['https://local.example.test/one.png'];
    vm.runInContext(loader, context);
    assert.equal(loads.length, 2, 'source changes invalidate the loader key');
    assert.equal(merges[1].revision, 2);
});

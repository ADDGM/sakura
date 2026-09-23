const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

function runLoader(file, overrides = {}) {
    const requests = [];
    const loads = [];
    const warnings = [];
    const model = JSON.parse(read('live2d/model/pio/model.json'));
    const textures = JSON.parse(read('live2d/model/pio/textures.json'));
    const window = {
        SakuraLive2D: {enabled: true, modelPath: '/pio/', dressUrl: '/costume.png'},
        matchMedia: () => ({matches: true}),
        loadlive2d: (...args) => loads.push(args),
        console: {warn: value => warnings.push(value)},
        jQuery: {getJSON(url) {
            requests.push(url);
            const failed = overrides.fail === url;
            return {
                done(callback) {
                    if (!failed) callback(url.endsWith('model.json') ? model : textures);
                    return this;
                },
                fail(callback) {
                    if (failed) callback();
                    return this;
                }
            };
        }},
        ...overrides.window
    };
    vm.runInNewContext(read('live2d/js/' + file), {window});
    return {requests, loads, warnings, model};
}

for (const file of ['run_local.js', 'run_field.js']) {
    for (const [label, window] of [
        ['missing jQuery', {jQuery: undefined}],
        ['missing getJSON', {jQuery: {}}],
        ['mobile viewport', {matchMedia: () => ({matches: false})}],
        ['missing matchMedia', {matchMedia: undefined}],
        ['missing runtime', {loadlive2d: undefined}],
        ['disabled widget', {SakuraLive2D: {enabled: false}}]
    ]) {
        test(file + ': ' + label + ' makes no resource requests', () => {
            const result = runLoader(file, {window});
            assert.equal(result.requests.length, 0);
            assert.equal(result.loads.length, 0);
        });
    }

    test(file + ': desktop loads one model without changing its source data', () => {
        const result = runLoader(file);
        assert.equal(result.loads.length, 1);
        assert.equal(result.loads[0][0], 'live2d');
        assert.equal(result.loads[0][3].textures.length, 1);
        assert.deepEqual(result.model, JSON.parse(read('live2d/model/pio/model.json')));
        assert.match(result.loads[0][3].textures[0], file === 'run_local.js' ? /^\/pio\// : /^\/costume\.png$/);
    });

    test(file + ': failed model request does not load the runtime', () => {
        const result = runLoader(file, {fail: '/pio/model.json'});
        assert.equal(result.loads.length, 0);
        assert.equal(result.warnings.length, 1);
    });
}

test('failed local texture request does not load the runtime', () => {
    const result = runLoader('run_local.js', {fail: '/pio/textures.json'});
    assert.equal(result.loads.length, 0);
    assert.equal(result.warnings.length, 1);
});

test('every tip has a WordPress translation and a catalog entry in each locale', () => {
    const source = read('functions.php');
    const strings = new Map([...source.matchAll(/'([A-Za-z]+)' => __\('((?:\\.|[^'])*)', 'sakura'\)/g)]
        .map(match => [match[1], match[2].replace(/\\(['\\])/g, '$1')]));
    const rules = JSON.parse(read('live2d/message.json'));
    const catalogs = ['sakura.pot', 'en_US.po', 'zh_CN.po', 'zh_TW.po', 'ja.po'].map(file => read('languages/' + file));
    for (const rule of [...rules.mouseover, ...rules.click]) {
        for (const key of rule.text) {
            assert.ok(strings.has(key), 'Missing translation key: ' + key);
            for (const catalog of catalogs) {
                assert.ok(catalog.includes('msgid ' + JSON.stringify(strings.get(key))), 'Missing catalog entry: ' + key);
            }
        }
    }
});

test('Tia and Pio model, motion and texture references exist', () => {
    for (const name of ['tia', 'pio']) {
        const directory = 'live2d/model/' + name + '/';
        const model = JSON.parse(read(directory + 'model.json'));
        const textures = JSON.parse(read(directory + 'textures.json'));
        const resources = [model.model, ...(model.textures || []),
            ...Object.values(model.motions).flat().map(motion => motion.file),
            ...textures.pioUrl.flatMap(texture => Object.values(texture))];
        for (const resource of resources) {
            assert.ok(fs.existsSync(path.join(root, directory, resource)), directory + resource);
        }
    }
});

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../../js/sakura-app.js'), 'utf8');
const start = source.indexOf('var b = mashiro_option.meting_api_url');
const defaults = source.slice(start, source.indexOf('for (var f = 0;', start));
const perPlayer = source.match(/var g = d\.dataset\.api \|\| b;[\s\S]*?g = g\.replace[^\n]+/)[0];
const signed = 'https://example.test/music?server=:server&type=:type&id=:id&music_token=fixture-signature';

function select(extra = {}, api) {
    return vm.runInNewContext(defaults + perPlayer + '\ng;', {
        mashiro_option: {meting_api_url: signed},
        d: {dataset: {server: 'netease', type: 'playlist', id: '123', api}},
        ...extra
    });
}

test('default music URL keeps the server-generated token without requiring Poi.nonce', () => {
    assert.equal(select(), 'https://example.test/music?server=netease&type=playlist&id=123&music_token=fixture-signature');
});

test('an explicit global API overrides the signed default', () => {
    assert.equal(select({meting_api: 'https://custom.test/:type/:id'}), 'https://custom.test/playlist/123');
});

test('per-player API has priority over the global override', () => {
    assert.equal(select({meting_api: 'https://global.test/:id'}, 'https://player.test/:server/:id'), 'https://player.test/netease/123');
});

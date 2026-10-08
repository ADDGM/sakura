const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const {test} = require('node:test');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const normalize = source => source.replace(/\r\n/g, '\n').replace(/\/\/# sourceMappingURL=.*$/gm, '').trim();
const bundle = normalize(read('cdn/js/lib.js'));

for (const file of ['01.jquery.min.js', '02.jquery.pjax.min.js', '07.APlayer.min.js', '17.query.fancybox.min.js']) {
    test(file + ': the actual frontend bundle contains exactly the maintained source', () => {
        const source = normalize(read('cdn/js/src/' + file));
        const offset = bundle.indexOf(source);
        assert.ok(offset >= 0, 'Source changes must also reach cdn/js/lib.js');
        assert.equal(bundle.indexOf(source, offset + 1), -1, 'Do not load duplicate copies');
    });
}

test('jQuery is the full official 4.0.0 build', () => {
    const source = normalize(read('cdn/js/src/01.jquery.min.js'));
    const sha256 = crypto.createHash('sha256').update(source).digest('hex');
    assert.equal(sha256, '39a546ea9ad97f8bfaf5d3e0e8f8556adb415e470e59007ada9759dce472adaa');
    assert.doesNotMatch(bundle, /jQuery v3\./);
    assert.match(read('cdn/js/src/01.jquery.LICENSE.txt'), /MIT/);
});

test('latest GPL Fancybox and PJAX releases identify their local migration', () => {
    assert.match(read('cdn/js/src/02.jquery.pjax.min.js'), /jquery-pjax 2\.0\.1/);
    assert.match(read('cdn/js/src/17.query.fancybox.min.js'), /fancyBox 3\.5\.7/);
    assert.match(read('cdn/js/src/02.jquery.pjax.min.js'), /Sakura: use native/);
    assert.match(read('cdn/js/src/17.query.fancybox.min.js'), /Sakura: use native/);
    assert.doesNotMatch(bundle, /version:"3\.5\.6"|jquery-migrate/i);
});

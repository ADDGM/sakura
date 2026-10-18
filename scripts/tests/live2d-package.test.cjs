// Source archives feed the CDN; installable archives carry only the small catalog.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const archive = execFileSync('git', ['archive', '--format=tar', '--worktree-attributes', '--prefix=sakura/', 'HEAD'], {
    cwd: root, maxBuffer: 128 * 1024 * 1024,
});
const names = execFileSync('tar', ['-tf', '-'], {input: archive, maxBuffer: 1024 * 1024}).toString().split(/\r?\n/);
const textureRoot = 'sakura/live2d/costumes/pio/textures/';
const textures = fs.readdirSync(path.join(root, 'live2d/costumes/pio/textures'));

test('CDN source archive retains every managed PNG and excludes development notes', () => {
    assert.equal(textures.length, 107);
    for (const file of textures) assert.ok(names.includes(textureRoot + file), 'Missing CDN source: ' + file);
    assert.ok(!names.some(name => name.startsWith('sakura/.agents/')));
});

for (const workflow of ['ci.yml', 'release.yml']) {
    test(workflow + ' installs the catalog and bundled models without the extension PNGs', () => {
        const source = fs.readFileSync(path.join(root, '.github/workflows', workflow), 'utf8');
        const pipeline = source.split(/\r?\n/).find(line => line.includes('git archive --format=tar --prefix=sakura/'));
        assert.ok(pipeline, 'Archive pipeline exists');
        const extraction = pipeline.split('|')[1];
        assert.match(extraction, /^\s*tar\s/);
        const excludes = [...extraction.matchAll(/--exclude="([^"]+)"/g)].map(match => '--exclude=' + match[1]);
        const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'sakura-package-'));
        try {
            execFileSync('tar', [...excludes, '-xf', '-', '-C', temporary, 'sakura/live2d'], {input: archive});
            const theme = path.join(temporary, 'sakura');
            assert.ok(!fs.existsSync(path.join(theme, 'live2d/costumes/pio/textures')));
            const expected = fs.readFileSync(path.join(root, 'live2d/costumes/pio/catalog.json'), 'utf8').replace(/\r\n/g, '\n');
            assert.equal(fs.readFileSync(path.join(theme, 'live2d/costumes/pio/catalog.json'), 'utf8').replace(/\r\n/g, '\n'), expected);
            for (const model of ['pio', 'tia']) {
                const modelRoot = path.join(theme, 'live2d/model', model);
                assert.ok(fs.existsSync(path.join(modelRoot, 'model.moc')));
                const bundled = JSON.parse(fs.readFileSync(path.join(modelRoot, 'textures.json'), 'utf8')).pioUrl;
                for (const entry of bundled) {
                    for (const texture of Object.values(entry)) assert.ok(fs.existsSync(path.join(modelRoot, texture)));
                }
            }
        } finally {
            fs.rmSync(temporary, {recursive: true, force: true});
        }
    });
}

// Generate the trusted, model-specific catalog shipped without the large PNG library.
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const root = path.resolve(__dirname, '..');
const sha256 = data => createHash('sha256').update(data).digest('hex');
const verifiedModels = {pio: '1545fdb296bb2d1c9c6f1c295b3a336705618aa467c45e9be8dbc9cb1ffb704d'};

function buildCatalog(model, base = root) {
    const modelRoot = path.join(base, 'live2d/model', model);
    const modelHash = sha256(fs.readFileSync(path.join(modelRoot, 'model.moc')));
    if (modelHash !== verifiedModels[model]) throw new Error('Model changed: repeat costume compatibility verification for ' + model);
    const bundled = new Set(JSON.parse(fs.readFileSync(path.join(modelRoot, 'textures.json'), 'utf8')).pioUrl
        .flatMap(entry => Object.values(entry)).map(file => sha256(fs.readFileSync(path.join(modelRoot, file)))));
    const textureRoot = path.join(base, 'live2d/costumes', model, 'textures');
    const hashes = new Set();
    const costumes = [];
    let duplicates = 0;
    for (const file of fs.readdirSync(textureRoot).sort()) {
        if (!/^[a-z0-9]+(?:-[a-z0-9]+)*\.png$/.test(file)) throw new Error('Invalid texture name: ' + file);
        const absolute = path.join(textureRoot, file);
        if (!fs.lstatSync(absolute).isFile()) throw new Error('Not a regular file: ' + file);
        const data = fs.readFileSync(absolute);
        if (!data.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex')) || data.readUInt32BE(16) !== 1024 || data.readUInt32BE(20) !== 1024) throw new Error('Unexpected PNG: ' + file);
        const hash = sha256(data);
        if (hashes.has(hash)) throw new Error('Duplicate library content: ' + file);
        hashes.add(hash);
        if (bundled.has(hash)) { duplicates++; continue; }
        costumes.push({id: file.slice(0, -4), texture: 'textures/' + file, bytes: data.length, sha256: hash});
    }
    const identity = {model: {id: model, sha256: modelHash}, costumes};
    return {catalog: {version: 1, packVersion: sha256(JSON.stringify(identity)), ...identity}, duplicates};
}

if (require.main === module) {
    if (process.argv.slice(2).some(arg => arg !== '--check')) throw new Error('Usage: node scripts/build-live2d-costumes.cjs [--check]');
    for (const model of Object.keys(verifiedModels)) {
        const {catalog, duplicates} = buildCatalog(model);
        const output = JSON.stringify(catalog, null, 2) + '\n';
        const file = path.join(root, 'live2d/costumes', model, 'catalog.json');
        if (process.argv.includes('--check')) {
            if (fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n') !== output) throw new Error('Catalog needs regeneration: ' + model);
        } else fs.writeFileSync(file, output);
        console.log(model + ': ' + catalog.costumes.length + ' extensions, ' + duplicates + ' bundled duplicates');
    }
}
module.exports = {buildCatalog};

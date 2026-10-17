const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');
const source = fs.readFileSync(path.join(__dirname, '../../live2d/js/costume.js'), 'utf8');

function fixture({ready = true, mobile = false} = {}) {
    const events = {}, clicks = [], images = [], timers = new Map(), deleted = [], uploaded = [];
    const button = {hidden: true, disabled: true, attributes: {}, setAttribute(k, v) {this.attributes[k] = v;}, addEventListener(_, fn) {clicks.push(fn);}};
    const status = {textContent: ''};
    let nextId = 0, timerId = 0, uploadError = 0;
    const parameters = new Map();
    const gl = {MAX_TEXTURE_SIZE: 1, ACTIVE_TEXTURE: 2, UNPACK_FLIP_Y_WEBGL: 3, UNPACK_PREMULTIPLY_ALPHA_WEBGL: 4,
        TEXTURE0: 5, TEXTURE_BINDING_2D: 6, TEXTURE_2D: 7, RGBA: 8, UNSIGNED_BYTE: 9, TEXTURE_MAG_FILTER: 10,
        TEXTURE_MIN_FILTER: 11, LINEAR: 12, TEXTURE_WRAP_S: 13, TEXTURE_WRAP_T: 14, CLAMP_TO_EDGE: 15, NO_ERROR: 0,
        getParameter(k) {return k === 1 ? 4096 : parameters.get(k);}, activeTexture(v) {parameters.set(2, v);},
        pixelStorei(k, v) {parameters.set(k, v);}, createTexture() {return {id: ++nextId};},
        bindTexture(_, v) {parameters.set(6, v);}, texImage2D(...args) {uploaded.push(args.at(-1).src);}, texParameteri() {},
        getError() {return uploadError;}, deleteTexture(t) {deleted.push(t);}};
    const original = {id: 'original'}, draw = {textures: [original]};
    const model = {getDrawParam: () => draw, isPremultipliedAlpha: () => false, setTexture(i, t) {draw.textures[i] = t;}};
    let activeTarget = ready ? {gl, model} : null;
    const api = {getTextureTarget: () => activeTarget};
    const window = {sakuraLive2D: api, SakuraLive2D: {enabled: true, messages: {costumeChanging:'changing',costumeChanged:'changed',costumeFailed:'failed'}},
        matchMedia: () => ({matches: !mobile}), location: {href: 'https://example.test/'},
        addEventListener(type, fn) {events[type] = fn;}, setTimeout(fn) {timers.set(++timerId, fn); return timerId;}, clearTimeout(id) {timers.delete(id);},
        Image: class {constructor() {this.naturalWidth=1024;this.naturalHeight=1024;images.push(this);}}};
    const document = {querySelector: selector => selector.includes('costume-status') ? status : button,
        createElement: () => ({set textContent(v) {this.innerHTML = v;}})};
    const context = vm.createContext({window,document,URL,Promise,Set,Math,Date,Array,Error});
    vm.runInContext(source, context);
    return {api,button,status,images,timers,deleted,uploaded,original,draw,model,gl,clicks,
        configure: () => api.configureCostumes({textures:['/a.png','/b.png','/c.png'],current:'/a.png'}),
        ready() {activeTarget={gl,model};events['sakura:live2d-ready']();},
        changeTarget() {activeTarget={gl,model:{}};}, failUpload() {uploadError=1282;}, rerun() {vm.runInContext(source,context);}};
}

test('costume button waits for model readiness and hides for a single costume or mobile', () => {
    const f=fixture({ready:false}); f.configure(); assert.equal(f.button.hidden,true);
    f.ready(); assert.equal(f.button.hidden,false); assert.equal(f.button.disabled,false);
    f.api.configureCostumes({textures:['/a.png','/a.png'],current:'/a.png'}); assert.equal(f.button.hidden,true);
    assert.equal(fixture({mobile:true}).api.configureCostumes,undefined);
});

test('successful swaps skip the current costume and release only the replaced texture', async () => {
    const f=fixture(); f.configure(); let previous='/a.png';
    for (let i=0;i<6;i++) {
        const old=f.draw.textures[0], p=f.api.changeCostume(), image=f.images.at(-1);
        assert.notEqual(image.src,previous); assert.equal(f.draw.textures[0],old);
        image.onload(); assert.equal(await p,true); assert.equal(f.deleted.at(-1),old);
        assert.notEqual(f.draw.textures[0],old); previous=image.src;
    }
    assert.equal(f.deleted.length,6); assert.equal(f.status.textContent,'changed');
});

test('rapid repeated clicks issue one image request and unlock after completion', async () => {
    const f=fixture(); f.configure(); const p=f.api.changeCostume();
    assert.equal(f.button.disabled,true); assert.equal(await f.api.changeCostume(),false); assert.equal(f.images.length,1);
    f.images[0].onload(); await p; assert.equal(f.button.disabled,false); assert.equal(f.button.attributes['aria-busy'],'false');
    f.rerun(); assert.equal(f.clicks.length,1);
});

test('image failure and timeout preserve the old costume and allow retry', async () => {
    const f=fixture(); f.configure(); let p=f.api.changeCostume(); f.images.at(-1).onerror();
    assert.equal(await p,false); assert.equal(f.draw.textures[0],f.original); assert.equal(f.status.textContent,'failed');
    p=f.api.changeCostume(); [...f.timers.values()][0](); assert.equal(await p,false);
    assert.equal(f.images.at(-1).onload,null); assert.equal(f.deleted.length,0);
    assert.equal(f.images.at(-1).src, '', 'Timeout must cancel the request before retrying the same URL');
    p=f.api.changeCostume(); f.images.at(-1).onload(); assert.equal(await p,true);
});

test('failed GL upload deletes the candidate and preserves the displayed texture', async () => {
    const f=fixture(); f.configure(); f.failUpload(); const p=f.api.changeCostume(); f.images.at(-1).onload();
    assert.equal(await p,false); assert.equal(f.draw.textures[0],f.original);
    assert.equal(f.deleted.length,1); assert.notEqual(f.deleted[0],f.original); assert.equal(f.button.disabled,false);
});

test('a replaced model cannot receive a stale image response', async () => {
    const f=fixture(); f.configure(); const p=f.api.changeCostume(); f.changeTarget(); f.images.at(-1).onload();
    assert.equal(await p,false); assert.equal(f.draw.textures[0],f.original); assert.equal(f.uploaded.length,0);
});

test('remote swaps preserve existing query parameters and vary the cache key', async () => {
    const f=fixture(); f.api.configureCostumes({remote:true,current:'https://example.test/png/pio?mode=test&value=a%2Bb'});
    const values=[];
    for(let i=0;i<2;i++) {
        const p=f.api.changeCostume(), image=f.images.at(-1), u=new URL(image.src);
        assert.equal(u.searchParams.get('mode'),'test'); assert.equal(u.searchParams.get('value'),'a+b');
        values.push(u.searchParams.get('sakura_costume')); image.onload(); assert.equal(await p,true);
    }
    assert.notEqual(values[0],values[1]);
});

test('oversized decoded images do not allocate a GPU texture', async () => {
    const f=fixture(); f.configure(); const p=f.api.changeCostume(); f.images[0].naturalWidth=8192; f.images[0].onload();
    assert.equal(await p,false); assert.equal(f.uploaded.length,0); assert.equal(f.deleted.length,0);
});

test('late catalog merges preserve the current costume and an in-flight swap', async () => {
    const f = fixture();
    const revision = f.api.configureCostumes({textures: ['/a.png', '/b.png'], current: '/a.png'});
    const p = f.api.changeCostume(); assert.equal(f.images[0].src, '/b.png');
    assert.equal(f.api.mergeCostumes(['/b.png'], revision), true);
    assert.equal(f.button.disabled, true); assert.equal(await f.api.changeCostume(), false);
    f.images[0].onload(); assert.equal(await p, true);
    f.api.mergeCostumes(['/b.png', '/a.png'], revision);
    const q = f.api.changeCostume(); assert.equal(f.images[1].src, '/a.png');
    f.images[1].onload(); assert.equal(await q, true);
});

test('catalog and image responses from an old configuration cannot overwrite the new one', async () => {
    const f = fixture();
    const old = f.api.configureCostumes({textures: ['/a.png', '/b.png'], current: '/a.png'});
    const p = f.api.changeCostume();
    f.api.configureCostumes({textures: ['/new.png'], current: '/new.png'});
    assert.equal(f.api.mergeCostumes(['/stale.png'], old), false);
    f.images[0].onload(); assert.equal(await p, false); assert.equal(f.uploaded.length, 0);
    assert.equal(f.button.hidden, true);
    const remote = f.api.configureCostumes({remote: true, current: '/random.png'});
    assert.equal(f.api.mergeCostumes(['/catalog.png'], remote), false);
});

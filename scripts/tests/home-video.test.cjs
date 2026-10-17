const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../../js/sakura-app.js'), 'utf8');
const library = source.slice(source.indexOf('var hlsLibraryRequest ='), source.indexOf('function destroyCoverHls()'));
const controller = source.slice(source.indexOf('function createHomeVideoController('), source.indexOf('var home = location.href'));
const navigation = source.slice(source.indexOf('$(document).pjax('), source.indexOf("window.addEventListener('popstate'"));

function deferred() {
    let state, value;
    const callbacks = {done: [], fail: []};
    const d = {
        done(fn) { callbacks.done.push(fn); if (state === 'done') fn(value); return d; },
        fail(fn) { callbacks.fail.push(fn); if (state === 'fail') fn(value); return d; },
        resolve(data) { if (!state) { state = 'done'; value = data; callbacks.done.forEach(fn => fn(value)); } return d; },
        reject(data) { if (!state) { state = 'fail'; value = data; callbacks.fail.forEach(fn => fn(value)); } return d; },
        promise() { return d; }
    };
    return d;
}

function harness({type = 'mp4', live = 'open', empty = false, native = false} = {}) {
    const views = [], streams = [], requests = [], timers = new Map(), handlers = new Map();
    let timerId = 0, home = true;
    const video = {
        src: '', currentTime: 0, duration: NaN, connected: true, plays: 0, pauses: 0,
        error: null, listeners: new Map(), seekable: {length: 0},
        canPlayType: () => native ? 'probably' : '',
        addEventListener(event, fn) { this.listeners.set(event, fn); },
        removeEventListener(event, fn) { if (this.listeners.get(event) === fn) this.listeners.delete(event); },
        removeAttribute() { this.src = ''; },
        load() { this.currentTime = 0; this.duration = NaN; this.error = null; },
        pause() { this.pauses++; },
        play() { this.plays++; return this.playResult ? this.playResult() : Promise.resolve(); },
        emit(event) { this.listeners.get(event)?.(); }
    };
    const chain = {
        on(event, ...args) { if (!handlers.has(event)) handlers.set(event, []); handlers.get(event).push(args.at(-1)); return chain; },
        pjax() { return chain; }, css() { return chain; }, each() { return chain; }
    };
    const $ = selector => selector === 'h1.main-title' ? {length: home ? 1 : 0} : chain;
    $.Deferred = deferred;
    $.ajax = options => { const request = deferred(); requests.push({options, request}); return request; };
    const context = vm.createContext({
        $, console, setTimeout(fn) { timers.set(++timerId, fn); return timerId; }, clearTimeout(id) { timers.delete(id); },
        document: {documentElement: {contains: element => element.connected}},
        mashiro_option: {template_url: '/theme'}, Poi: {},
        Siren: {PE() {}}, destroyCoverHls() {}, coverVideoIni() {}
    });
    context.window = context;
    vm.runInContext(library + '\n' + controller, context);
    const config = {sources: empty ? [] : [{url: '/video.' + type + '?x=1,2&sig=%2B', type}], live,
        labels: {loading: 'loading', paused: 'paused', failed: 'failed', blocked: 'blocked', empty: 'empty'}};
    const player = context.createHomeVideoController(video, config, (phase, message, selected) => views.push({phase, message, selected}));
    context.homeVideo = player;
    vm.runInContext(navigation, context);
    function installHls(supported = true) {
        context.Hls = function () {
            streams.push(this);
            this.events = new Map();
            this.on = (event, fn) => this.events.set(event, fn);
            this.loadSource = url => { this.url = url; };
            this.attachMedia = media => { this.media = media; };
            this.destroy = () => { this.destroyed = true; };
        };
        context.Hls.isSupported = () => supported;
        context.Hls.Events = {MANIFEST_PARSED: 'manifest', ERROR: 'error'};
    }
    player.enter(true);
    return {context, video, config, player, views, requests, streams, timers, installHls,
        state: () => views.at(-1),
        manifest() { streams.at(-1).events.get('manifest')(); },
        navigate(isHome) {
            for (const handler of handlers.get('pjax:beforeReplace')) handler();
            home = isHome;
            for (const handler of handlers.get('pjax:end')) handler();
        }
    };
}

for (const type of ['mp4', 'webm']) test(type + ' starts on demand and pauses/resumes without loading HLS', () => {
    const e = harness({type});
    assert.equal(e.video.src, '');
    assert.equal(e.video.plays, 0);
    e.player.toggle();
    assert.equal(e.video.src, e.config.sources[0].url);
    assert.equal(e.requests.length, 0);
    e.video.emit('playing');
    assert.equal(e.state().phase, 'playing');
    assert.equal(e.timers.size, 0);
    e.player.toggle();
    assert.equal(e.state().phase, 'paused');
    e.player.toggle();
    assert.equal(e.video.plays, 2);
});

test('an empty list and an inactive page never load media', () => {
    const e = harness({empty: true});
    e.player.toggle();
    assert.equal(e.state().message, 'empty');
    assert.equal(e.video.plays, 0);
    const inactive = harness();
    inactive.player.leave();
    inactive.player.toggle();
    inactive.player.next();
    assert.equal(inactive.video.plays, 0);
});

test('home HLS shares an in-flight library request with other consumers', () => {
    const e = harness({type: 'm3u8'});
    e.context.ensureHls();
    e.player.toggle();
    assert.equal(e.requests.length, 1);
    e.installHls();
    e.requests[0].request.resolve();
    assert.equal(e.streams.length, 1);
    assert.equal(e.streams[0].url, e.config.sources[0].url);
    assert.equal(e.video.plays, 0);
    e.manifest();
    assert.equal(e.video.plays, 1);
});

for (const failure of [false, true]) test('native HLS fallback after ' + (failure ? 'library failure' : 'unsupported MSE'), () => {
    const e = harness({type: 'm3u8', native: true});
    e.player.toggle();
    if (failure) e.requests[0].request.reject(new Error('network'));
    else { e.installHls(false); e.requests[0].request.resolve(); }
    assert.equal(e.video.src, e.config.sources[0].url);
    assert.equal(e.video.plays, 1);
    assert.equal(e.streams.length, 0);
});

test('failed library loading restores controls and permits a fresh request', () => {
    const e = harness({type: 'm3u8'});
    e.player.toggle();
    e.requests[0].request.reject(new Error('network'));
    assert.equal(e.state().phase, 'error');
    assert.equal(e.timers.size, 0);
    e.player.toggle();
    assert.equal(e.requests.length, 2);
    e.installHls();
    e.requests[1].request.resolve();
    e.manifest();
    assert.equal(e.video.plays, 1);
});

test('switching and leaving invalidate library, manifest and media callbacks', () => {
    const e = harness({type: 'm3u8'});
    e.player.toggle();
    e.player.next();
    e.installHls();
    e.requests[0].request.resolve();
    assert.equal(e.streams.length, 1);
    const old = e.streams[0];
    const canplay = e.video.listeners.get('canplay');
    e.navigate(false);
    assert.equal(old.destroyed, true);
    old.events.get('manifest')();
    canplay();
    assert.equal(e.video.plays, 0);
    assert.equal(e.video.listeners.size, 0);
    e.navigate(true);
    assert.equal(e.streams.length, 2);
    e.manifest();
    assert.equal(e.video.plays, 1);
});

for (const live of ['open', 'close']) test('two PJAX history round trips respect Live=' + live, () => {
    const e = harness({live});
    e.player.toggle();
    e.video.emit('playing');
    for (let round = 0; round < 2; round++) {
        e.video.currentTime = 12 + round;
        e.navigate(false);
        assert.equal(e.video.src, '');
        const before = e.video.plays;
        e.navigate(true);
        assert.equal(e.video.plays, before + (live === 'open' ? 1 : 0));
        if (live === 'close') e.player.toggle();
        e.video.duration = 100;
        e.video.emit('loadedmetadata');
        assert.equal(e.video.currentTime, 12 + round);
        e.video.emit('playing');
        e.player.enter(true);
        assert.equal(e.video.plays, before + 1);
    }
});

test('manual pause survives navigation even with Live enabled', () => {
    const e = harness();
    e.player.toggle();
    e.video.emit('playing');
    e.player.toggle();
    e.navigate(false);
    e.navigate(true);
    assert.equal(e.video.plays, 1);
    e.player.toggle();
    assert.equal(e.video.plays, 2);
});

test('navigation while the library is loading does not initialize hidden media', () => {
    const e = harness({type: 'm3u8'});
    e.player.toggle();
    e.navigate(false);
    e.installHls();
    e.requests[0].request.resolve();
    assert.equal(e.streams.length, 0);
    e.navigate(true);
    assert.equal(e.streams.length, 1);
});

test('manual cancellation while loading invalidates pending startup', () => {
    const e = harness({type: 'm3u8'});
    e.player.toggle();
    e.player.toggle();
    e.installHls();
    e.requests[0].request.resolve();
    assert.equal(e.streams.length, 0);
    assert.equal(e.state().phase, 'paused');
    assert.equal(e.timers.size, 0);
});

test('autoplay rejection is recoverable and a stale rejection cannot stop the new source', async () => {
    const e = harness();
    e.video.playResult = () => Promise.reject(Object.assign(new Error('policy'), {name: 'NotAllowedError'}));
    e.player.toggle();
    await Promise.resolve();
    assert.equal(e.state().message, 'blocked');
    assert.equal(e.timers.size, 0);
    let rejectOld;
    e.video.playResult = () => new Promise((resolve, reject) => { rejectOld = reject; });
    e.player.toggle();
    e.video.playResult = () => Promise.resolve();
    e.player.next();
    e.video.emit('playing');
    rejectOld(new Error('old request aborted'));
    await Promise.resolve();
    assert.equal(e.state().phase, 'playing');
});

for (const reason of ['timeout', 'media', 'fatal', 'ended']) test(reason + ' releases resources and permits replay', () => {
    const e = harness({type: 'm3u8'});
    e.installHls();
    e.player.toggle();
    const previous = e.streams[0];
    if (reason === 'timeout') [...e.timers.values()][0]();
    if (reason === 'media') { e.video.error = {code: 4}; e.video.emit('error'); }
    if (reason === 'fatal') previous.events.get('error')('error', {fatal: true});
    if (reason === 'ended') e.video.emit('ended');
    assert.equal(previous.destroyed, true);
    assert.equal(e.timers.size, 0);
    assert.equal(e.video.listeners.size, 0);
    assert.equal(e.state().phase, reason === 'ended' ? 'idle' : 'error');
    e.player.toggle();
    assert.equal(e.streams.length, 2);
});

test('live restoration clamps a stale timestamp to the current seekable window', () => {
    const e = harness();
    e.player.toggle();
    e.video.emit('playing');
    e.video.currentTime = 5;
    e.navigate(false);
    e.navigate(true);
    e.video.duration = Infinity;
    e.video.seekable = {length: 1, start: () => 40, end: () => 60};
    e.video.emit('canplay');
    assert.equal(e.video.currentTime, 40);
});

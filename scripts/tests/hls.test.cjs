const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {test} = require('node:test');

const root = path.resolve(__dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const source = read('js/sakura-app.js');
const coverSource = source.slice(source.indexOf('/*视频feature*/'), source.indexOf('function copy_code_block()'));
const playerSource = source.match(/if \(mashiro_option.float_player_on\) \{[\s\S]+?    aplayerF\(\);\r?\n\}/)[0];
const initSource = source.slice(source.indexOf('mashiro_global.ini ='), source.indexOf('function setCookie('));
const navigationSource = source.slice(source.indexOf('$(document).pjax('), source.indexOf("window.addEventListener('popstate'"));
// Exercise the bundled player's real type detection and HLS/native fallback.
const setAudioSource = read('cdn/js/src/07.APlayer.min.js').match(/key:"setAudio",value:(function\(e\)\{[\s\S]+?)\},\{key:"theme"/)[1];

function deferred() {
    let state;
    let value;
    const callbacks = {done: [], fail: []};
    const result = {
        done(fn) { callbacks.done.push(fn); if (state === 'done') fn(value); return result; },
        fail(fn) { callbacks.fail.push(fn); if (state === 'fail') fn(value); return result; },
        resolve(data) { if (!state) { state = 'done'; value = data; callbacks.done.forEach(fn => fn(data)); } return result; },
        reject(data) { if (!state) { state = 'fail'; value = data; callbacks.fail.forEach(fn => fn(data)); } return result; },
        promise() { return result; }
    };
    return result;
}

function harness({dataset = {url: '/song.mp3'}, cover = false, loading = false} = {}) {
    const requests = [], apiRequests = [], players = [], streams = [], warnings = [], ready = [];
    const container = {dataset, connected: true};
    const video = {
        connected: true, src: '', plays: 0, pauses: 0, loads: 0, listeners: {},
        classList: {contains: value => value === 'hls'},
        getAttribute: () => '/cover.m3u8',
        canPlayType: () => 'probably',
        addEventListener(event, fn) { this.listeners[event] = fn; },
        removeEventListener(event, fn) { if (this.listeners[event] === fn) delete this.listeners[event]; },
        removeAttribute(name) { delete this[name]; },
        pause() { this.pauses++; },
        load() { this.loads++; },
        play() { this.plays++; return Promise.resolve(); }
    };
    const dom = {cover: cover ? video : null};
    const chain = {on() { return this; }, off() { return this; }, addClass() { return this; }, removeClass() { return this; }};
    const $ = () => chain;
    $.Deferred = deferred;
    $.ajax = options => { const request = deferred(); requests.push({options, request}); return request; };
    const context = vm.createContext({
        $, mashiro_option: {float_player_on: true, template_url: '/theme', meting_api_url: '/playlist'},
        mashiro_global: {variables: {has_hls: true}}, Poi: {nonce: 'test'},
        console: {warn: (...args) => warnings.push(args), log() {}},
        document: {
            readyState: loading ? 'loading' : 'complete',
            addEventListener: (event, fn) => ready.push(fn),
            documentElement: {contains: element => element.connected},
            querySelectorAll: () => container.connected ? [container] : [],
            getElementById: () => dom.cover
        },
        XMLHttpRequest: function () {
            apiRequests.push(this);
            this.open = () => {};
            this.send = () => {};
            this.respond = audio => {
                this.readyState = 4;
                this.status = 200;
                this.responseText = JSON.stringify(audio);
                this.onreadystatechange();
            };
        }
    });
    context.window = context;
    const setAudio = vm.runInContext('(' + setAudioSource + ')', context);
    context.APlayer = function (options) {
        this.options = options;
        this.container = options.container;
        this.audio = {canPlayType: () => 'probably'};
        this.paused = true;
        this.seek = () => {};
        this.notice = value => warnings.push(value);
        this.on = () => {};
        this.lrc = {hide() {}, show() {}};
        this.destroy = () => { this.destroyed = true; if (this.hls) this.hls.destroy(); };
        this.switchAudio = index => setAudio.call(this, options.audio[index]);
        this.switchAudio(0);
        players.push(this);
    };
    vm.runInContext(coverSource + '\n' + playerSource, context);
    function installHls({supported = true} = {}) {
        context.Hls = function () {
            streams.push(this);
            this.events = {};
            this.loadSource = url => { this.url = url; };
            this.attachMedia = media => { this.media = media; };
            this.on = (event, fn) => { this.events[event] = fn; };
            this.destroy = () => { this.destroyed = true; };
        };
        context.Hls.isSupported = () => supported;
        context.Hls.Events = {MANIFEST_PARSED: 'manifestParsed'};
    }
    return {context, container, video, dom, requests, apiRequests, players, streams, warnings, ready, installHls};
}

function pjaxLifecycle(env) {
    const handlers = new Map();
    const chain = env.context.$();
    chain.length = 0;
    chain.pjax = chain.css = chain.fadeOut = chain.each = () => chain;
    chain.on = (event, ...args) => {
        if (!handlers.has(event)) handlers.set(event, []);
        handlers.get(event).push(args.at(-1));
        return chain;
    };
    for (const name of ['lazyload', 'social_share', 'post_list_show_animation', 'copy_code_block',
        'checkskinSecter', 'scrollBar', 'load_bangumi', 'pjaxInit']) {
        env.context[name] = () => {};
    }
    env.context.Siren = {AH() {}, PE() {}, CE() {}, MNH() {}};
    // Run the real initialization and event bindings; unrelated UI has no role in this lifecycle.
    vm.runInContext(initSource + '\n' + navigationSource, env.context);
    return event => {
        for (const handler of handlers.get(event) || []) handler.call(env.context.document);
    };
}

test('ordinary audio initializes immediately without requesting HLS', () => {
    const env = harness();
    assert.equal(env.players.length, 1);
    assert.equal(env.players[0].audio.src, '/song.mp3');
    assert.equal(env.requests.length, 0);
});

test('explicit normal type is not inferred as HLS from its URL', () => {
    const env = harness({dataset: {url: '/song.m3u8', type: 'normal'}});
    assert.equal(env.players[0].audio.src, '/song.m3u8');
    assert.equal(env.requests.length, 0);
});

for (const dataset of [{url: '/song.M3U8?token=test'}, {url: '/stream', type: 'hls'}]) {
    test('HLS audio waits for the library without requiring a cover: ' + dataset.url, () => {
        const env = harness({dataset});
        assert.equal(env.players.length, 0);
        assert.equal(env.requests.length, 1);
        assert.equal(env.requests[0].options.url, '/theme/cdn/js/src/16.hls.js');
        env.installHls();
        env.requests[0].request.resolve();
        assert.equal(env.players.length, 1);
        assert.equal(env.streams[0].url, dataset.url);
    });
}

test('a later HLS track also loads the dependency before creating the playlist', () => {
    const env = harness({dataset: {id: 'list', server: 'test', type: 'playlist'}});
    env.apiRequests[0].respond([{url: '/song.mp3'}, {url: '/song.m3u8#fragment'}]);
    assert.equal(env.players.length, 0);
    assert.equal(env.requests.length, 1);
    env.installHls();
    env.requests[0].request.resolve();
    env.players[0].switchAudio(1);
    assert.equal(env.streams[0].url, '/song.m3u8#fragment');
});

test('cover and audio share one request even when the old flag says loaded', () => {
    const env = harness({dataset: {url: '/song.m3u8'}, cover: true});
    env.context.coverVideoIni();
    assert.equal(env.requests.length, 1);
    env.installHls();
    env.requests[0].request.resolve();
    assert.equal(env.streams.length, 2);
    assert.deepEqual(env.streams.map(stream => stream.url), ['/song.m3u8', '/cover.m3u8']);
    let reused = false;
    env.context.ensureHls().done(Hls => { reused = Hls === env.context.Hls; });
    assert.equal(reused, true);
    assert.equal(env.requests.length, 1);
});

for (const failure of ['network', 'missing constructor']) {
    test('failed library load can be retried: ' + failure, () => {
        const env = harness({dataset: {url: '/song.m3u8'}});
        if (failure === 'network') env.requests[0].request.reject();
        else env.requests[0].request.resolve();
        assert.equal(env.players.length, 0);
        assert.equal(env.warnings.length, 1);
        env.context.aplayerF();
        assert.equal(env.requests.length, 2);
        env.installHls();
        env.requests[1].request.resolve();
        assert.equal(env.players.length, 1);
    });
}

test('a removed player container cannot be initialized by a pending script', () => {
    const env = harness({dataset: {url: '/song.m3u8'}});
    env.container.connected = false;
    env.installHls();
    env.requests[0].request.resolve();
    assert.equal(env.players.length, 0);
});

test('a newer initialization invalidates pending callbacks on the same container', () => {
    const env = harness({dataset: {url: '/song.m3u8'}});
    env.context.aplayerF();
    assert.equal(env.requests.length, 1);
    env.installHls();
    env.requests[0].request.resolve();
    assert.equal(env.players.length, 1);
});

test('stale playlist responses do not request HLS or create a player', () => {
    const env = harness({dataset: {id: 'list'}});
    env.context.aplayerF();
    env.apiRequests[0].respond([{url: '/old.m3u8'}]);
    assert.equal(env.requests.length, 0);
    env.apiRequests[1].respond([{url: '/new.mp3'}]);
    assert.equal(env.players.length, 1);
    assert.equal(env.players[0].audio.src, '/new.mp3');
});

test('only the latest DOM ready initialization runs', () => {
    const env = harness({loading: true});
    env.context.aplayerF();
    env.ready.forEach(fn => fn());
    assert.equal(env.players.length, 1);
});

test('reinitialization destroys the previous player before replacing it', () => {
    const env = harness();
    env.context.aplayerF();
    assert.equal(env.players.length, 2);
    assert.equal(env.players[0].destroyed, true);
});

test('cover replacement cancels stale initialization and releases its HLS instance', () => {
    const env = harness({cover: true});
    env.context.coverVideoIni();
    env.context.destroyCoverHls();
    env.installHls();
    env.requests[0].request.resolve();
    assert.equal(env.streams.length, 0);
    env.context.coverVideoIni();
    assert.equal(env.streams.length, 1);
    env.streams[0].events.manifestParsed();
    assert.equal(env.video.plays, 1);
    env.context.destroyCoverHls();
    assert.equal(env.streams[0].destroyed, true);
    env.streams[0].events.manifestParsed();
    assert.equal(env.video.plays, 1);
});

test('native HLS fallback works for both bundled APlayer and cover', async () => {
    const env = harness({dataset: {url: '/song.m3u8'}, cover: true});
    env.context.coverVideoIni();
    env.installHls({supported: false});
    env.requests[0].request.resolve();
    assert.equal(env.streams.length, 0);
    assert.equal(env.players[0].audio.src, '/song.m3u8');
    assert.equal(env.video.src, '/cover.m3u8');
    env.video.play = () => Promise.reject(new Error('Autoplay blocked'));
    env.video.listeners.loadedmetadata();
    await new Promise(resolve => setImmediate(resolve));
    env.context.destroyCoverHls();
    assert.equal(env.video.pauses, 1);
    assert.equal(env.video.loads, 1);
    assert.equal(env.video.src, undefined);
    assert.equal(env.video.listeners.loadedmetadata, undefined);
});

test('AJAX PJAX navigation replaces the outgoing HLS cover exactly once', () => {
    const env = harness({cover: true});
    env.installHls();
    const emit = pjaxLifecycle(env);
    env.context.mashiro_global.ini.normalize();
    const previous = env.streams[0];
    emit('pjax:beforeSend');
    emit('pjax:send');
    emit('pjax:beforeReplace');
    assert.equal(previous.destroyed, true);
    env.video.connected = false;
    const replacement = {...env.video, connected: true, listeners: {}};
    env.dom.cover = replacement;
    emit('pjax:success');
    emit('pjax:complete');
    emit('pjax:end');
    assert.equal(env.streams.length, 2);
    assert.equal(env.streams[1].media, replacement);
    assert.equal(env.streams.filter(stream => !stream.destroyed).length, 1);
    previous.events.manifestParsed();
    assert.equal(env.video.plays, 0);
    env.streams[1].events.manifestParsed();
    assert.equal(replacement.plays, 1);
});

test('cached PJAX history releases the old cover and recreates it on return', () => {
    const env = harness({cover: true});
    env.installHls();
    const emit = pjaxLifecycle(env);
    env.context.mashiro_global.ini.normalize();
    // jquery-pjax cache hits emit start/beforeReplace/end, without Ajax send/complete events.
    emit('pjax:start');
    emit('pjax:beforeReplace');
    assert.equal(env.streams[0].destroyed, true);
    env.video.connected = false;
    env.dom.cover = null;
    emit('pjax:end');
    assert.equal(env.streams.filter(stream => !stream.destroyed).length, 0);

    emit('pjax:start');
    emit('pjax:beforeReplace');
    env.video.connected = true;
    env.dom.cover = env.video;
    emit('pjax:end');
    assert.equal(env.streams.length, 2);
    assert.equal(env.streams[1].media, env.video);
    env.streams[1].events.manifestParsed();
    assert.equal(env.video.plays, 1);
    assert.equal(env.requests.length, 0);
});

test('cached PJAX history detaches and restores native HLS with one metadata handler', () => {
    const env = harness({cover: true});
    env.installHls({supported: false});
    const emit = pjaxLifecycle(env);
    env.context.mashiro_global.ini.normalize();
    const previousHandler = env.video.listeners.loadedmetadata;
    emit('pjax:start');
    emit('pjax:beforeReplace');
    assert.equal(env.video.src, undefined);
    assert.equal(env.video.listeners.loadedmetadata, undefined);
    assert.equal(env.video.pauses, 1);
    assert.equal(env.video.loads, 1);
    env.video.connected = false;
    env.dom.cover = null;
    emit('pjax:end');
    previousHandler();
    assert.equal(env.video.plays, 0);

    emit('pjax:start');
    emit('pjax:beforeReplace');
    env.video.connected = true;
    env.dom.cover = env.video;
    emit('pjax:end');
    assert.equal(env.video.src, '/cover.m3u8');
    assert.notEqual(env.video.listeners.loadedmetadata, previousHandler);
    env.video.listeners.loadedmetadata();
    assert.equal(env.video.plays, 1);
});

test('the shipped HLS runtime exposes the APIs used by both consumers', () => {
    const context = vm.createContext({console, setTimeout, clearTimeout, performance});
    context.self = context;
    context.window = context;
    vm.runInContext(read('cdn/js/src/16.hls.js'), context);
    assert.equal(typeof context.Hls, 'function');
    for (const method of ['loadSource', 'attachMedia', 'on', 'destroy']) {
        assert.equal(typeof context.Hls.prototype[method], 'function');
    }
    assert.equal(typeof context.Hls.Events.MANIFEST_PARSED, 'string');
    assert.equal(context.Hls.isSupported(), false);
});

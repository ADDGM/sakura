(function (window, document) {
    'use strict';

    var config = window.SakuraLive2D || {};
    var api = window.sakuraLive2D = window.sakuraLive2D || {};
    var button = document.querySelector('#landlord .change-costume');
    var status = document.querySelector('#landlord .costume-status');
    if (!config.enabled || !button || api.configureCostumes || !window.matchMedia ||
        !window.matchMedia('(min-width: 861px)').matches) {
        return;
    }

    var textures = [];
    var current = '';
    var remoteBase = '';
    var busy = false;
    var sequence = 0;
    var generation = 0;

    function target() {
        return typeof api.getTextureTarget === 'function' ? api.getTextureTarget() : null;
    }

    function updateButton() {
        button.hidden = !(remoteBase || textures.length > 1) || !target();
        button.disabled = busy || button.hidden;
        button.setAttribute('aria-busy', String(busy));
    }

    function announce(key) {
        var text = (config.messages || {})[key] || '';
        if (status) status.textContent = text;
        if (key === 'costumeFailed' && typeof config.showMessage === 'function') {
            var escaped = document.createElement('span');
            escaped.textContent = text;
            config.showMessage(escaped.innerHTML, 4000);
        }
    }

    function replaceTexture(url, revision) {
        var initial = target();
        return new Promise(function (resolve, reject) {
            if (!initial) { reject(new Error('Model is not ready')); return; }
            var image = new window.Image();
            var settled = false;
            var timer = window.setTimeout(function () { finish(new Error('Texture request timed out')); }, 15000);
            function finish(error) {
                if (settled) return;
                settled = true;
                window.clearTimeout(timer);
                image.onload = image.onerror = null;
                if (error) {
                    image.src = '';
                    reject(error);
                } else resolve();
            }
            image.crossOrigin = 'anonymous';
            image.onerror = function () { finish(new Error('Texture image failed to load')); };
            image.onload = function () {
                var latest = target();
                if (revision !== generation || !latest || latest.model !== initial.model || latest.gl !== initial.gl) {
                    finish(new Error('Model changed while loading texture'));
                    return;
                }
                var gl = initial.gl;
                var texture = null;
                try {
                    var width = image.naturalWidth;
                    var height = image.naturalHeight;
                    var limit = gl.getParameter(gl.MAX_TEXTURE_SIZE);
                    if (!width || !height || width > limit || height > limit) throw new Error('Unsupported texture size');
                    var active = gl.getParameter(gl.ACTIVE_TEXTURE);
                    var flip = gl.getParameter(gl.UNPACK_FLIP_Y_WEBGL);
                    var premultiplied = gl.getParameter(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL);
                    gl.activeTexture(gl.TEXTURE0);
                    var binding = gl.getParameter(gl.TEXTURE_BINDING_2D);
                    try {
                        texture = gl.createTexture();
                        if (!texture) throw new Error('Texture allocation failed');
                        gl.bindTexture(gl.TEXTURE_2D, texture);
                        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, true);
                        gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, !initial.model.isPremultipliedAlpha());
                        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, image);
                        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
                        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
                        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
                        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
                        if (gl.getError() !== gl.NO_ERROR) throw new Error('Texture upload failed');
                    } finally {
                        gl.bindTexture(gl.TEXTURE_2D, binding);
                        gl.pixelStorei(gl.UNPACK_FLIP_Y_WEBGL, flip);
                        gl.pixelStorei(gl.UNPACK_PREMULTIPLY_ALPHA_WEBGL, premultiplied);
                        gl.activeTexture(active);
                    }
                    // Commit only a complete upload; failed requests keep the visible texture.
                    var previous = initial.model.getDrawParam().textures[0];
                    initial.model.setTexture(0, texture);
                    texture = null;
                    if (previous) gl.deleteTexture(previous);
                    finish();
                } catch (error) {
                    if (texture) gl.deleteTexture(texture);
                    finish(error);
                }
            };
            image.src = url;
        });
    }

    api.configureCostumes = function (options) {
        generation++;
        textures = Array.from(new Set((options.textures || []).filter(function (url) { return typeof url === 'string' && url; })));
        current = options.current;
        remoteBase = options.remote ? current : '';
        updateButton();
        return generation;
    };

    // Catalog responses may add candidates, but must not reset an active swap.
    api.mergeCostumes = function (urls, revision) {
        if (revision !== generation || remoteBase) return false;
        textures = Array.from(new Set(textures.concat(urls)));
        updateButton();
        return true;
    };

    api.changeCostume = function () {
        if (busy || !target()) return Promise.resolve(false);
        var choices = textures.filter(function (url) { return url !== current; });
        if (!remoteBase && !choices.length) return Promise.resolve(false);
        var next;
        if (remoteBase) {
            var url = new URL(remoteBase, window.location.href);
            url.searchParams.set('sakura_costume', Date.now() + '-' + (++sequence));
            next = url.href;
        } else {
            next = choices[Math.floor(Math.random() * choices.length)];
        }
        busy = true;
        var revision = generation;
        updateButton();
        announce('costumeChanging');
        return replaceTexture(next, revision).then(function () {
            if (revision !== generation) return false;
            current = next;
            announce('costumeChanged');
            return true;
        }, function () {
            if (revision === generation) announce('costumeFailed');
            return false;
        }).finally(function () {
            busy = false;
            updateButton();
        });
    };

    button.addEventListener('click', api.changeCostume);
    window.addEventListener('sakura:live2d-ready', updateButton);
    updateButton();
}(window, document));

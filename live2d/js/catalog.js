(function (window) {
    'use strict';

    var api = window.sakuraLive2D = window.sakuraLive2D || {};
    if (api.loadCostumeCatalog) return;
    var active = null;
    var maxBytes = 65536;
    var namePattern = /^[a-zA-Z0-9][a-zA-Z0-9._-]{0,63}$/;

    function catalogURL(value) {
        if (typeof value !== 'string' || value.length > 2048 || !/^https?:\/\//i.test(value)) throw new Error('Invalid catalog URL');
        var url = new URL(value);
        if (url.username || url.password || url.hash || !/\.json$/i.test(url.pathname) ||
            (window.location.protocol === 'https:' && url.protocol !== 'https:')) throw new Error('Invalid catalog URL');
        return url;
    }

    function texturesFrom(text, url, model) {
        if (new window.Blob([text]).size > maxBytes) throw new Error('Catalog is too large');
        var data = JSON.parse(text);
        if (!data || data.version !== 1 || typeof data.packVersion !== 'string' || !namePattern.test(data.packVersion) ||
            !data.model || data.model.id !== model.id || data.model.sha256 !== model.sha256 ||
            !/^[a-f0-9]{64}$/.test(model.sha256) || !Array.isArray(data.costumes) || data.costumes.length > 256) {
            throw new Error('Unsupported catalog');
        }
        var ids = new Map();
        var urls = new Set();
        data.costumes.forEach(function (entry) {
            if (!entry || typeof entry.id !== 'string' || !namePattern.test(entry.id) ||
                typeof entry.texture !== 'string' || entry.texture.length > 240 || !/\.png$/i.test(entry.texture) ||
                !entry.texture.split('/').every(function (part) { return /^[a-zA-Z0-9_-][a-zA-Z0-9._-]*$/.test(part); })) {
                throw new Error('Invalid costume entry');
            }
            // Only literal relative PNG paths inside the catalog directory are accepted.
            var texture = new URL(entry.texture, url).href;
            if (ids.has(entry.id) && ids.get(entry.id) !== texture) throw new Error('Conflicting costume ID');
            ids.set(entry.id, texture);
            urls.add(texture);
        });
        return Array.from(urls);
    }

    api.loadCostumeCatalog = function (options, revision, isCurrent) {
        var key = JSON.stringify([options.url, options.model, revision]);
        if (active && active.key === key) return active.promise;
        if (active && active.cancel) active.cancel();
        var job = {key: key};
        active = job;
        job.promise = new Promise(function (resolve) {
            var xhr;
            var settled = false;
            function finish(text, url) {
                if (settled) return;
                settled = true;
                if (xhr) xhr.onload = xhr.onerror = xhr.ontimeout = xhr.onabort = xhr.onprogress = null;
                var success = false;
                try {
                    if (text !== undefined && active === job && isCurrent()) {
                        success = api.mergeCostumes(texturesFrom(text, url, options.model), revision);
                    }
                } catch (error) { /* An invalid pack leaves the bundled candidates intact. */ }
                if (!success && active === job && isCurrent() && window.console) {
                    window.console.warn('Sakura Live2D costume catalog unavailable; using bundled costumes.');
                }
                resolve(success);
            }
            job.cancel = function () {
                finish();
                if (xhr) xhr.abort();
            };
            try {
                var url = catalogURL(options.url).href;
                xhr = new window.XMLHttpRequest();
                xhr.open('GET', url, true);
                xhr.timeout = 10000;
                xhr.onload = function () {
                    // Reject redirects so the directory and origin cannot silently change.
                    if (xhr.status >= 200 && xhr.status < 300 && xhr.responseURL === url) finish(xhr.responseText, url);
                    else finish();
                };
                xhr.onerror = xhr.ontimeout = xhr.onabort = function () { finish(); };
                xhr.onprogress = function (event) {
                    if (event.loaded > maxBytes || (event.lengthComputable && event.total > maxBytes)) job.cancel();
                };
                xhr.send();
            } catch (error) { finish(); }
        });
        return job.promise;
    };
}(window));

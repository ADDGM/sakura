(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !config.modelPath || !$ || typeof $.getJSON !== 'function' || typeof window.loadlive2d !== 'function' ||
        !window.matchMedia || !window.matchMedia('(min-width: 861px)').matches) {
        return;
    }

    var api = window.sakuraLive2D = window.sakuraLive2D || {};
    function configKey(value) {
        return JSON.stringify(['local', value.modelPath, value.catalogUrl || '', value.catalogModel || {}]);
    }
    var key = configKey(config);
    if (api.loaderState && api.loaderState.key === key) return;
    var state = api.loaderState = {key: key};
    function isCurrent() {
        var latest = window.SakuraLive2D || {};
        return api.loaderState === state && latest.enabled && configKey(latest) === key;
    }

    function reportLoadError(resource) {
        if (!isCurrent()) return;
        api.loaderState = null;
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('Sakura Live2D resource failed to load: ' + resource);
        }
    }

    $.getJSON(config.modelPath + 'model.json').done(function (model) {
        if (!isCurrent()) return;
        $.getJSON(config.modelPath + 'textures.json').done(function (textures) {
            if (!isCurrent()) return;
            var modelObj = JSON.parse(JSON.stringify(model));
            var textureList = (Array.isArray(textures.pioUrl) ? textures.pioUrl : []).map(function (entry) {
                var keys = entry && typeof entry === 'object' ? Object.keys(entry) : [];
                var path = keys.length ? entry[keys[0]] : '';
                return typeof path === 'string' && path ? config.modelPath + path : '';
            }).filter(Boolean);
            if (!textureList.length) {
                reportLoadError(config.modelPath + 'textures.json');
                return;
            }

            modelObj.textures = [textureList[Math.floor(Math.random() * textureList.length)]];
            var revision;
            if (typeof api.configureCostumes === 'function') {
                revision = api.configureCostumes({textures: textureList, current: modelObj.textures[0]});
            }
            window.loadlive2d('live2d', config.modelPath, '', modelObj);
            if (config.catalogUrl && revision !== undefined && typeof api.loadCostumeCatalog === 'function') {
                api.loadCostumeCatalog({url: config.catalogUrl, model: config.catalogModel}, revision, isCurrent);
            }
        }).fail(function () {
            reportLoadError(config.modelPath + 'textures.json');
        });
    }).fail(function () {
        reportLoadError(config.modelPath + 'model.json');
    });
}(window, window.jQuery));

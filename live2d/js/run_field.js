(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !config.modelPath || !config.dressUrl || !$ || typeof $.getJSON !== 'function' || typeof window.loadlive2d !== 'function' ||
        !window.matchMedia || !window.matchMedia('(min-width: 861px)').matches) {
        return;
    }

    var api = window.sakuraLive2D = window.sakuraLive2D || {};
    var key = JSON.stringify(['remote', config.modelPath, config.dressUrl]);
    if (api.loaderState && api.loaderState.key === key) return;
    var state = api.loaderState = {key: key};
    function isCurrent() {
        var latest = window.SakuraLive2D || {};
        return api.loaderState === state && latest.enabled &&
            JSON.stringify(['remote', latest.modelPath, latest.dressUrl]) === key;
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
        var modelObj = JSON.parse(JSON.stringify(model));
        modelObj.textures = [config.dressUrl];
        if (window.sakuraLive2D && typeof window.sakuraLive2D.configureCostumes === 'function') {
            window.sakuraLive2D.configureCostumes({remote: true, current: config.dressUrl});
        }
        window.loadlive2d('live2d', config.modelPath, '', modelObj);
    }).fail(function () {
        reportLoadError(config.modelPath + 'model.json');
    });
}(window, window.jQuery));

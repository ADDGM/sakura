(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !config.modelPath || !$ || typeof $.getJSON !== 'function' || typeof window.loadlive2d !== 'function' ||
        !window.matchMedia || !window.matchMedia('(min-width: 861px)').matches) {
        return;
    }

    function reportLoadError(resource) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('Sakura Live2D resource failed to load: ' + resource);
        }
    }

    $.getJSON(config.modelPath + 'model.json').done(function (model) {
        $.getJSON(config.modelPath + 'textures.json').done(function (textures) {
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
            if (window.sakuraLive2D && typeof window.sakuraLive2D.configureCostumes === 'function') {
                window.sakuraLive2D.configureCostumes({textures: textureList, current: modelObj.textures[0]});
            }
            window.loadlive2d('live2d', config.modelPath, '', modelObj);
        }).fail(function () {
            reportLoadError(config.modelPath + 'textures.json');
        });
    }).fail(function () {
        reportLoadError(config.modelPath + 'model.json');
    });
}(window, window.jQuery));

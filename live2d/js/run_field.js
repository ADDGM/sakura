(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !config.modelPath || !config.dressUrl || !$ || typeof $.getJSON !== 'function' || typeof window.loadlive2d !== 'function' ||
        !window.matchMedia || !window.matchMedia('(min-width: 861px)').matches) {
        return;
    }

    function reportLoadError(resource) {
        if (window.console && typeof window.console.warn === 'function') {
            window.console.warn('Sakura Live2D resource failed to load: ' + resource);
        }
    }

    $.getJSON(config.modelPath + 'model.json').done(function (model) {
        var modelObj = JSON.parse(JSON.stringify(model));
        modelObj.textures = [config.dressUrl];
        window.loadlive2d('live2d', config.modelPath, '', modelObj);
    }).fail(function () {
        reportLoadError(config.modelPath + 'model.json');
    });
}(window, window.jQuery));

(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !config.modelPath || !config.dressUrl || typeof window.loadlive2d !== 'function') {
        return;
    }

    $.getJSON(config.modelPath + 'model.json', function (model) {
        var modelObj = JSON.parse(JSON.stringify(model));
        modelObj.textures = [config.dressUrl];
        window.loadlive2d('live2d', config.modelPath, '', modelObj);
    });
}(window, window.jQuery));

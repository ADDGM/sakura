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
            var textureList = textures.pioUrl || [];
            if (!textureList.length) {
                reportLoadError(config.modelPath + 'textures.json');
                return;
            }

            var randomTexture = textureList[Math.floor(Math.random() * textureList.length)];
            var textureKeys = randomTexture && typeof randomTexture === 'object' ? Object.keys(randomTexture) : [];
            var texturePath = textureKeys.length ? randomTexture[textureKeys[0]] : '';
            if (!texturePath) {
                reportLoadError(config.modelPath + 'textures.json');
                return;
            }

            modelObj.textures = [config.modelPath + texturePath];
            window.loadlive2d('live2d', config.modelPath, '', modelObj);
        }).fail(function () {
            reportLoadError(config.modelPath + 'textures.json');
        });
    }).fail(function () {
        reportLoadError(config.modelPath + 'model.json');
    });
}(window, window.jQuery));

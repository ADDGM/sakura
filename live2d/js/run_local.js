(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !config.modelPath || typeof window.loadlive2d !== 'function') {
        return;
    }

    $.getJSON(config.modelPath + 'model.json', function (model) {
        $.getJSON(config.modelPath + 'textures.json', function (textures) {
            var modelObj = JSON.parse(JSON.stringify(model));
            var textureList = textures.pioUrl || [];
            if (!textureList.length) {
                return;
            }

            var randomTexture = textureList[Math.floor(Math.random() * textureList.length)];
            var texturePath = randomTexture[Object.keys(randomTexture)[0]];
            modelObj.textures = [config.modelPath + texturePath];
            window.loadlive2d('live2d', config.modelPath, '', modelObj);
        });
    });
}(window, window.jQuery));

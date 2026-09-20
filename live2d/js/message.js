(function (window, $) {
    'use strict';

    var config = window.SakuraLive2D || {};
    if (!config.enabled || !$ || !window.matchMedia || !window.matchMedia('(min-width: 861px)').matches) {
        return;
    }

    var $landlord = $('#landlord');
    if (!$landlord.length) {
        return;
    }

    var $message = $landlord.find('.message');
    var $hideButton = $landlord.find('.hide-button');
    var tipsEventNamespace = '.sakuraLive2DTips';

    function pickText(text) {
        return Array.isArray(text) ? text[Math.floor(Math.random() * text.length)] : text;
    }

    function renderTip(template, context) {
        var tokenReg = /(\\)?\{([^\{\}\\]+)(\\)?\}/g;
        return String(template).replace(tokenReg, function (word, slash1, token, slash2) {
            if (slash1 || slash2) {
                return word.replace('\\', '');
            }

            var current = context;
            var variables = token.replace(/\s/g, '').split('.');
            for (var i = 0; i < variables.length; i += 1) {
                current = current[variables[i]];
                if (current === undefined || current === null) {
                    return '';
                }
            }
            return current;
        });
    }

    function showMessage(text, timeout) {
        $message.stop(true, true).html(text).fadeTo(200, 1);
        window.setTimeout(function () {
            $message.stop(true, true).fadeTo(200, 0);
        }, Number.isFinite(timeout) ? timeout : 4000);
    }

    function initTips() {
        if (!config.messagePath) {
            return;
        }

        $.getJSON(config.messagePath + 'message.json').done(function (result) {
            $(document).off(tipsEventNamespace);
            $.each(result.mouseover || [], function (index, tip) {
                $(document).on('mouseenter' + tipsEventNamespace, tip.selector, function () {
                    showMessage(renderTip(pickText(tip.text), {text: $(this).text()}), 3000);
                });
            });
            $.each(result.click || [], function (index, tip) {
                $(document).on('click' + tipsEventNamespace, tip.selector, function () {
                    showMessage(renderTip(pickText(tip.text), {text: $(this).text()}), 3000);
                });
            });
        });
    }

    config.showMessage = showMessage;
    window.live2d_Tips = initTips;

    $hideButton.hide().on('click.sakuraLive2D', function () {
        $landlord.hide();
    });
    $landlord.on('mouseenter.sakuraLive2D', function () {
        $hideButton.stop(true, true).fadeIn(200);
    }).on('mouseleave.sakuraLive2D', function () {
        $hideButton.stop(true, true).fadeOut(200);
    });

    initTips();
}(window, window.jQuery));

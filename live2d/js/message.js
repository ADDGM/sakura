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
    var $dragHandle = $landlord.find('.drag-handle');
    var $resetPosition = $landlord.find('.reset-position');
    var tipsEventNamespace = '.sakuraLive2DTips';
    var positionStorageKey = 'sakura-live2d-position';
    var defaultPosition = {left: 30, bottom: 0};
    var savedPosition = readPosition();
    var playerOffset = 0;
    var dragState = null;

    function clamp(value, minimum, maximum) {
        return Math.min(Math.max(value, minimum), maximum);
    }

    function readPosition() {
        try {
            var value = JSON.parse(window.localStorage.getItem(positionStorageKey));
            if (value && Number.isFinite(value.left) && Number.isFinite(value.bottom)) {
                return {left: value.left, bottom: value.bottom};
            }
        } catch (error) {
            // Ignore unavailable or malformed local storage and use defaults.
        }
        return {left: defaultPosition.left, bottom: defaultPosition.bottom};
    }

    function writePosition(position) {
        try {
            window.localStorage.setItem(positionStorageKey, JSON.stringify(position));
        } catch (error) {
            // Private browsing and blocked storage must not break the widget.
        }
    }

    function getBounds() {
        var width = $landlord.outerWidth() || 280;
        var height = $landlord.outerHeight() || 250;
        return {
            maxLeft: Math.max(0, window.innerWidth - width),
            maxBottom: Math.max(0, window.innerHeight - height)
        };
    }

    function applyPosition(animate) {
        var bounds = getBounds();
        var left = clamp(savedPosition.left, 0, bounds.maxLeft);
        var bottom = clamp(clamp(savedPosition.bottom, 0, bounds.maxBottom) + playerOffset, 0, bounds.maxBottom);
        if (!animate) {
            $landlord.css('transition', 'none');
        }
        $landlord.css({left: left + 'px', bottom: bottom + 'px'});
        if (!animate) {
            window.requestAnimationFrame(function () {
                $landlord.css('transition', '');
            });
        }
    }

    function getPlayerRects() {
        var $player = $('.aplayer.aplayer-fixed');
        if (!$player.length || !$player.is(':visible')) {
            return [];
        }
        return $player.find('.aplayer-body, .aplayer-list, .aplayer-lrc').get().filter(function (element) {
            var style = window.getComputedStyle(element);
            if (!$(element).is(':visible') || style.visibility === 'hidden' || Number(style.opacity) === 0) {
                return false;
            }
            var rect = element.getBoundingClientRect();
            return rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.bottom > 0;
        }).map(function (element) {
            return element.getBoundingClientRect();
        }).sort(function (first, second) {
            return second.top - first.top;
        });
    }

    function updatePlayerOffset() {
        if (dragState) {
            return;
        }
        var bounds = getBounds();
        var left = clamp(savedPosition.left, 0, bounds.maxLeft);
        var bottom = clamp(savedPosition.bottom, 0, bounds.maxBottom);
        var width = $landlord.outerWidth() || 280;
        var height = $landlord.outerHeight() || 250;
        // Calculate from the saved position, never from an in-flight CSS transition.
        getPlayerRects().forEach(function (rect) {
            var screenBottom = window.innerHeight - bottom;
            if (left < rect.right && left + width > rect.left &&
                screenBottom > rect.top && screenBottom - height < rect.bottom) {
                bottom = Math.max(bottom, window.innerHeight - rect.top);
            }
        });
        playerOffset = clamp(bottom, 0, bounds.maxBottom) - clamp(savedPosition.bottom, 0, bounds.maxBottom);
        applyPosition(true);
    }

    function startDrag(event) {
        if (event.type === 'mousedown' && event.button !== 0) {
            return;
        }
        var original = event.originalEvent || event;
        var point = original.touches ? original.touches[0] : original;
        var rect = $landlord.get(0).getBoundingClientRect();
        dragState = {x: point.clientX, y: point.clientY, left: rect.left, top: rect.top};
        $landlord.addClass('is-dragging');
        event.preventDefault();
    }

    function moveDrag(event) {
        if (!dragState) {
            return;
        }
        var original = event.originalEvent || event;
        var point = original.touches ? original.touches[0] : original;
        var bounds = getBounds();
        var left = clamp(dragState.left + point.clientX - dragState.x, 0, bounds.maxLeft);
        var top = clamp(dragState.top + point.clientY - dragState.y, 0, bounds.maxBottom);
        savedPosition = {left: left, bottom: window.innerHeight - ($landlord.outerHeight() || 250) - top};
        playerOffset = 0;
        applyPosition(false);
        event.preventDefault();
    }

    function endDrag() {
        if (!dragState) {
            return;
        }
        dragState = null;
        $landlord.removeClass('is-dragging');
        writePosition(savedPosition);
        updatePlayerOffset();
    }

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
            return $('<span>').text(String(current)).html();
        });
    }

    function showMessage(text, timeout) {
        $message.stop(true, true).html(text).fadeTo(200, 1);
        window.setTimeout(function () {
            $message.stop(true, true).fadeTo(200, 0);
        }, Number.isFinite(timeout) ? timeout : 4000);
    }

    function initTips() {
        if (!config.messageUrl) {
            return;
        }

        $.getJSON(config.messageUrl).done(function (result) {
            $(document).off(tipsEventNamespace);
            $.each(result.mouseover || [], function (index, tip) {
                $(document).on('mouseenter' + tipsEventNamespace, tip.selector, function () {
                    var template = (config.messages || {})[pickText(tip.text)];
                    if (typeof template === 'string') {
                        showMessage(renderTip(template, {text: $(this).text()}), 3000);
                    }
                });
            });
            $.each(result.click || [], function (index, tip) {
                $(document).on('click' + tipsEventNamespace, tip.selector, function () {
                    var template = (config.messages || {})[pickText(tip.text)];
                    if (typeof template === 'string') {
                        showMessage(renderTip(template, {text: $(this).text()}), 3000);
                    }
                });
            });
        });
    }

    config.showMessage = showMessage;
    window.live2d_Tips = initTips;

    $hideButton.on('click.sakuraLive2D', function () {
        $landlord.hide();
    });
    $dragHandle.on('mousedown.sakuraLive2D touchstart.sakuraLive2D', startDrag);
    $dragHandle.on('keydown.sakuraLive2D', function (event) {
        var directions = {ArrowLeft: [-10, 0], ArrowRight: [10, 0], ArrowUp: [0, 10], ArrowDown: [0, -10]};
        var direction = directions[event.key];
        if (!direction) {
            return;
        }
        var bounds = getBounds();
        savedPosition.left = clamp(savedPosition.left + direction[0], 0, bounds.maxLeft);
        savedPosition.bottom = clamp(savedPosition.bottom + direction[1], 0, bounds.maxBottom);
        writePosition(savedPosition);
        updatePlayerOffset();
        event.preventDefault();
    });
    $(document).on('mousemove.sakuraLive2D touchmove.sakuraLive2D', moveDrag);
    $(document).on('mouseup.sakuraLive2D touchend.sakuraLive2D touchcancel.sakuraLive2D', endDrag);
    $resetPosition.on('click.sakuraLive2D', function () {
        savedPosition = {left: defaultPosition.left, bottom: defaultPosition.bottom};
        writePosition(savedPosition);
        updatePlayerOffset();
    });
    $(window).on('resize.sakuraLive2D', function () {
        applyPosition(false);
        updatePlayerOffset();
    }).on('load.sakuraLive2D', updatePlayerOffset).on('blur.sakuraLive2D', endDrag);
    window.sakuraLive2D = window.sakuraLive2D || {};
    window.sakuraLive2D.updatePlayerOffset = updatePlayerOffset;
    updatePlayerOffset();

    initTips();
}(window, window.jQuery));

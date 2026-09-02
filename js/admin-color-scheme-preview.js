(function () {
    'use strict';

    var config = window.sakuraAdminColorSchemePreview;
    if (!config || !config.schemes || !config.styleId) {
        return;
    }

    function getPreviewStyle() {
        var style = document.getElementById(config.styleId);
        if (!style) {
            style = document.createElement('style');
            style.id = config.styleId;
            document.head.appendChild(style);
        }
        return style;
    }

    function getPreviewStyleSheet() {
        return config.styleSheetId
            ? document.getElementById(config.styleSheetId)
            : null;
    }

    function applyPreview(scheme) {
        var hasPreviewScheme = Object.prototype.hasOwnProperty.call(config.schemes, scheme);
        var css = hasPreviewScheme
            ? config.schemes[scheme]
            : '';
        var style = getPreviewStyle();
        var styleSheet = getPreviewStyleSheet();
        style.textContent = css;
        if (styleSheet) {
            styleSheet.disabled = !hasPreviewScheme;
        }
    }

    function initializePreview() {
        var choices = document.querySelectorAll('input[name="admin_color"]');
        var options = document.querySelectorAll('#color-picker .color-option');
        if (!choices.length) {
            return;
        }

        choices.forEach(function (choice) {
            choice.addEventListener('change', function () {
                applyPreview(choice.value);
            });
        });

        // WordPress 配色卡会直接设置 checked，不一定派发 radio 的 change 事件。
        options.forEach(function (option) {
            option.addEventListener('click', function () {
                var choice = option.querySelector('input[name="admin_color"]');
                if (choice) {
                    applyPreview(choice.value);
                }
            });
        });

        var selected = document.querySelector('input[name="admin_color"]:checked');
        applyPreview(selected ? selected.value : '');
    }

    if ('loading' === document.readyState) {
        document.addEventListener('DOMContentLoaded', initializePreview);
    } else {
        initializePreview();
    }
}());

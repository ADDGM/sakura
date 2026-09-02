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

    function applyPreview(scheme) {
        var css = Object.prototype.hasOwnProperty.call(config.schemes, scheme)
            ? config.schemes[scheme]
            : '';
        var style = getPreviewStyle();
        style.textContent = css;
    }

    function initializePreview() {
        var choices = document.querySelectorAll('input[name="admin_color"]');
        if (!choices.length) {
            return;
        }

        choices.forEach(function (choice) {
            choice.addEventListener('change', function () {
                applyPreview(choice.value);
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

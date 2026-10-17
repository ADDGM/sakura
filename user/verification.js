(function (window, document) {
    'use strict';

    const initialized = new WeakSet();

    function init() {
        document.querySelectorAll('.sakura-verification').forEach(function (field) {
            if (initialized.has(field)) return;
            const range = field.querySelector('input[type="range"]');
            const marker = field.querySelector('input[name="verification"]');
            const label = field.querySelector('.sakura-verification-label');
            const form = field.closest('form');
            if (!range || !marker || !label || !form) return;
            initialized.add(field);
            let complete = false;

            function reset() {
                if (complete) return;
                range.value = '0';
                field.style.setProperty('--sakura-verification-progress', '0%');
            }

            // PJAX 缓存中的新表单重新验证，同一表单重复初始化则保持当前状态。
            range.disabled = false;
            marker.disabled = false;
            label.textContent = field.dataset.prompt;
            reset();
            range.addEventListener('input', function () {
                field.style.setProperty('--sakura-verification-progress', range.value + '%');
                if (Number(range.value) === 100) {
                    complete = true;
                    marker.disabled = true;
                    range.disabled = true;
                    label.textContent = 'OK!';
                }
            });
            range.addEventListener('pointerup', reset);
            range.addEventListener('pointercancel', reset);
            range.addEventListener('blur', reset);
            form.addEventListener('submit', function (event) {
                if (!complete) {
                    event.preventDefault();
                    range.focus();
                }
            });
        });
    }

    window.SakuraVerification = { init: init };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})(window, document);
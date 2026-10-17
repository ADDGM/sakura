(function ($) {
    'use strict';
    $(function () {
        $('#sakura-post-cover .sakura-cover-fields').each(function () {
            var box = $(this);
            var type = box.find('#sakura-cover-type');
            var video = box.find('#sakura-cover-video');
            var poster = box.find('#sakura-cover-poster');
            var preview = box.find('.sakura-cover-preview');
            var initial = {type: type.val(), video: video.val(), poster: poster.val()};
            var frame;

            function validUrl(value) {
                value = value.trim();
                if (!value) return true;
                if (!/^https?:\/\//i.test(value)) return false;
                try {
                    var parsed = new URL(value);
                    return /^https?:$/.test(parsed.protocol) && !!parsed.hostname && !parsed.username && !parsed.password
                        && !/[\x00-\x20<>"\\]/.test(value);
                } catch (error) {
                    return false;
                }
            }

            function validate() {
                var changed = type.val() !== initial.type || video.val() !== initial.video || poster.val() !== initial.poster;
                var valid = !changed || ((type.val() === '' || !!video.val().trim())
                    && (video.val() === initial.video || validUrl(video.val()))
                    && (poster.val() === initial.poster || validUrl(poster.val())));
                box.find('.sakura-cover-error').prop('hidden', valid);
                if (box.attr('data-block-editor') === '1') {
                    var editor = wp.data.dispatch('core/editor');
                    if (editor) {
                        if (valid) editor.unlockPostSaving('sakura-post-cover');
                        else editor.lockPostSaving('sakura-post-cover');
                    }
                }
                return valid;
            }

            function refresh() {
                box.find('.sakura-cover-video-fields').prop('hidden', type.val() === '');
                var url = poster.val().trim() || preview.attr('data-featured');
                if (url && /^https?:\/\//i.test(url)) {
                    preview.attr('src', url).prop('hidden', false);
                } else {
                    preview.removeAttr('src').prop('hidden', true);
                }
                validate();
            }

            if (box.attr('data-block-editor') === '1') {
                wp.apiFetch.use(function (options, next) {
                    if (options.url !== window._wpMetaBoxUrl || options.method !== 'POST'
                        || !options.body || typeof options.body.get !== 'function'
                        || !options.body.get('sakura_cover_nonce')) return next(options);
                    var fields = {type: type, video: video, poster: poster};
                    var submitted = {};
                    Object.keys(fields).forEach(function (field) {
                        submitted[field] = options.body.get('sakura_cover[' + field + ']');
                    });
                    function unknownResult() {
                        box.find('.sakura-cover-save-error').prop('hidden', false);
                        wp.data.dispatch('core/editor').lockPostSaving('sakura-post-cover-sync');
                    }
                    return next(options).then(function (response) {
                        return Promise.resolve().then(function () { return response.clone().text(); }).then(function (html) {
                            var saved = new DOMParser().parseFromString(html, 'text/html')
                                .querySelector('#sakura-post-cover .sakura-cover-fields');
                            if (!response.ok || !saved) throw new Error('Missing saved cover state');
                            var values = {}, hashes = {};
                            Object.keys(fields).forEach(function (field) {
                                var input = saved.querySelector('[name="sakura_cover[' + field + ']"]');
                                var original = saved.querySelector('[name="sakura_cover_original[' + field + ']"]');
                                if (!input || !original) throw new Error('Incomplete saved cover state');
                                values[field] = input.value;
                                hashes[field] = original.value;
                            });
                            var error = saved.getAttribute('data-save-error');
                            Object.keys(fields).forEach(function (field) {
                                box.find('[name="sakura_cover_original[' + field + ']"]').val(hashes[field]);
                                // 保存中的后续输入仍属下一次修改；只规范化尚未再编辑的值。
                                if (!error && fields[field].val() === submitted[field]) fields[field].val(values[field]);
                            });
                            initial = values;
                            refresh();
                            if (error === 'invalid') box.find('.sakura-cover-error').prop('hidden', false);
                            box.find('.sakura-cover-save-error').prop('hidden', error !== 'save');
                            wp.data.dispatch('core/editor').unlockPostSaving('sakura-post-cover-sync');
                        }).catch(unknownResult).then(function () { return response; });
                    }, function (error) {
                        unknownResult();
                        throw error;
                    });
                });
            }

            type.on('change', refresh);
            poster.on('input change', refresh);
            video.on('input change', validate);
            box.closest('form').on('submit.sakuraCover', function (event) {
                if (!validate()) {
                    event.preventDefault();
                    box.find('.sakura-cover-error')[0].scrollIntoView({block: 'center'});
                }
            });
            box.find('.sakura-cover-clear').on('click', function () {
                poster.val('').trigger('change');
            });
            box.find('.sakura-cover-choose').on('click', function () {
                if (!frame) {
                    frame = wp.media({
                        title: this.dataset.title,
                        button: {text: this.dataset.button},
                        library: {type: 'image'},
                        multiple: false
                    });
                    frame.on('select', function () {
                        var attachment = frame.state().get('selection').first();
                        if (attachment) poster.val(attachment.toJSON().url).trigger('change');
                    });
                }
                frame.open();
            });
            refresh();
        });
    });
}(jQuery));

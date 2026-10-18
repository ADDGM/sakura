(function (window, $) {
    'use strict';
    $(function () {
        var config = window.SakuraCostumeAdmin;
        var source = $('#live2d_costume_source');
        if (!config || !source.length) return;
        var models = $('input[name$="[live2d_m]"]');
        var download = $('#sakura-costume-download');
        var stop = $('#sakura-costume-stop');
        var restart = $('#sakura-costume-restart');
        var clear = $('#sakura-costume-clear');
        var progress = $('#sakura-costume-progress');
        var busy = false;
        var paused = false;
        function selectedModel() { return models.filter(':checked').val(); }
        function message(key, first, second) {
            return config.messages[key].replace('%1$s', String(first)).replace('%2$s', String(second));
        }
        function refresh() {
            var info = config.models[selectedModel()] || {count: 0};
            var managed = source.val() === 'cdn' || source.val() === 'local';
            $('#section-live2d_catalog').toggle(source.val() === 'custom');
            $('#section-live2d_i').toggle(source.val() === 'remote');
            $('#section-live2d_resources').toggle(managed);
            $('#sakura-costume-summary').text(info.count ? message('library', info.count, (info.bytes / 1048576).toFixed(2)) : config.messages.empty);
            download.prop('disabled', busy || !info.count || !info.downloadable);
            restart.prop('disabled', busy || !info.count || !info.downloadable);
            clear.prop('disabled', busy || !info.count);
            stop.prop('hidden', !busy).prop('disabled', paused);
            if (!busy) {
                if (!info.count) progress.text('');
                else if (source.val() === 'local') progress.text(config.messages[info.ready ? 'ready' : 'notReady']);
                else progress.text(info.downloadable ? '' : config.messages.unavailable);
            }
        }
        function request(model, mode, index) {
            return $.ajax({url: config.ajaxUrl, method: 'POST', dataType: 'json', timeout: 30000,
                data: {action: 'sakura_live2d_resources', nonce: config.nonce, model: model, mode: mode, index: index}})
                .then(function (response) {
                    if (!response || !response.success) throw new Error(response && response.data && response.data.message || config.messages.failed);
                    return response.data;
                });
        }
        stop.on('click', function () { paused = true; stop.prop('disabled', true); });
        download.add(restart).on('click', async function (event) {
            if (busy) return;
            var model = selectedModel();
            var outcome = '';
            busy = true;
            paused = false;
            models.prop('disabled', true);
            source.prop('disabled', true);
            refresh();
            progress.text(config.messages.checking);
            try {
                var status = await request(model, event.currentTarget.id === 'sakura-costume-restart' ? 'restart' : 'status');
                var completed = status.total - status.missing.length;
                for (var i = 0; i < status.missing.length && !paused; i++) {
                    var part;
                    do {
                        part = await request(model, 'download', status.missing[i]);
                        if (part.complete) completed++;
                        progress.text(message('progress', completed, status.total) + ' ' + message('chunk', (part.bytes / 1024).toFixed(1), (part.size / 1024).toFixed(1)));
                    } while (!part.complete && !paused);
                }
                if (paused) outcome = config.messages.paused;
                else {
                    await request(model, 'finish');
                    config.models[model].ready = true;
                    source.val('local');
                    outcome = config.messages.complete;
                }
            } catch (error) {
                outcome = error.responseJSON && error.responseJSON.data && error.responseJSON.data.message || error.message || config.messages.failed;
            } finally {
                busy = false;
                models.prop('disabled', false);
                source.prop('disabled', false);
                refresh();
                progress.text(outcome);
            }
        });
        clear.on('click', async function () {
            if (busy) return;
            var model = selectedModel();
            busy = true;
            models.prop('disabled', true);
            source.prop('disabled', true);
            refresh();
            var outcome;
            try {
                await request(model, 'clear');
                outcome = config.messages.cleared;
            } catch (error) {
                outcome = error.responseJSON && error.responseJSON.data && error.responseJSON.data.message || error.message || config.messages.failed;
            } finally {
                busy = false;
                models.prop('disabled', false);
                source.prop('disabled', false);
                refresh();
                progress.text(outcome);
            }
        });
        // Disabled fields must not disappear from a form saved during a download.
        source.closest('form').on('submit', function (event) { if (busy) event.preventDefault(); });
        models.on('change', refresh);
        source.on('change', refresh);
        refresh();
    });
}(window, window.jQuery));

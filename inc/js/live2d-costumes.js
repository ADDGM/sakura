(function (window, $) {
    'use strict';
    $(function () {
        var config = window.SakuraCostumeAdmin;
        var source = $('#live2d_costume_source');
        if (!config || !source.length) return;
        var models = $('input[name$="[live2d_m]"]');
        var panel = $('#sakura-costume-panel');
        var download = $('#sakura-costume-download');
        var stop = $('#sakura-costume-stop');
        var restart = $('#sakura-costume-restart');
        var clear = $('#sakura-costume-clear');
        var progress = $('#sakura-costume-progress');
        var historyLoad = $('#sakura-costume-history-load');
        var historyList = $('#sakura-costume-history-list');
        var historyStatus = $('#sakura-costume-history-status');
        var historyConfirm = $('#sakura-costume-history-confirm');
        var historyPreview = null;
        var views = {};
        var busy = false;
        var paused = false;
        var task = '';
        function selectedModel() { return models.filter(':checked').val(); }
        function message(key, first, second) {
            return config.messages[key].replace('%1$s', String(first)).replace('%2$s', String(second));
        }
        function refresh() {
            var model = selectedModel();
            var info = config.models[model] || {count: 0};
            var view = views[model] || {};
            var state = view.state || (info.ready ? 'ready' : 'idle');
            var labels = {checking: 'checkingLabel', verifying: 'checkingLabel', downloading: 'downloading',
                overwriting: 'overwriting', pausing: 'pausing', paused: 'pausedLabel', failed: 'failedLabel',
                complete: 'available', ready: 'available', clearing: 'checkingLabel'};
            var label = labels[state] || (source.val() === 'cdn' ? 'cdn' : 'idle');
            var text = view.text || '';
            if (state === 'complete' && source.val() !== 'local') text = config.messages.ready;
            $('#section-live2d_catalog').toggle(source.val() === 'custom');
            $('#section-live2d_i').toggle(source.val() === 'remote');
            $('#section-live2d_resources').toggle(source.val() === 'cdn' || source.val() === 'local');
            $('#sakura-costume-model').text(model === 'pio' ? 'Pio' : 'Tia');
            $('#sakura-costume-summary').text(info.count ? message('library', info.count, (info.bytes / 1048576).toFixed(2)) : config.messages.empty);
            if (!info.count) label = 'noLibrary';
            else if (!view.state) {
                if (source.val() === 'local') text = config.messages[info.ready ? 'ready' : 'notReady'];
                else if (!info.downloadable) text = config.messages.unavailable;
            }
            panel.attr('data-state', state);
            $('#sakura-costume-badge').text(config.messages[label]);
            download.prop('disabled', busy || !info.count || !info.downloadable).prop('hidden', busy && task === 'download');
            download.text(config.messages[state === 'paused' || state === 'failed' ? 'resume' : info.ready ? 'useLocal' : 'download']);
            restart.prop('disabled', busy || !info.count || !info.downloadable);
            clear.prop('disabled', busy || !info.count);
            stop.prop('hidden', !busy || task !== 'download').prop('disabled', paused);
            historyLoad.prop('disabled', busy || !info.count);
            historyList.find('button').prop('disabled', busy);
            historyConfirm.find('button').prop('disabled', busy);
            // Announce state changes, not every received chunk.
            if (progress.text() !== text) progress.text(text);
            var metrics = view.metrics;
            var showMeter = !!info.count && (!!metrics || state === 'checking');
            $('#sakura-costume-meter').prop('hidden', !showMeter);
            if (metrics) {
                var percent = metrics.size > 0 ? Math.min(100, Math.max(0, metrics.bytes / metrics.size * 100)) : 0;
                $('#sakura-costume-bar').attr('value', percent);
                $('#sakura-costume-percent').text(percent.toFixed(1).replace(/\.0$/, '') + '%');
                $('#sakura-costume-count').text(message('verified', metrics.completed, metrics.total));
                $('#sakura-costume-transfer').text(message('transferred', (metrics.bytes / 1048576).toFixed(2), (metrics.size / 1048576).toFixed(2)));
                $('#sakura-costume-chunk').text(metrics.chunk || '');
            } else {
                $('#sakura-costume-bar').removeAttr('value');
                $('#sakura-costume-percent, #sakura-costume-count, #sakura-costume-transfer, #sakura-costume-chunk').text('');
            }
        }
        function request(model, mode, index, version, ticket) {
            return $.ajax({url: config.ajaxUrl, method: 'POST', dataType: 'json', timeout: 30000,
                data: {action: 'sakura_live2d_resources', nonce: config.nonce, model: model, mode: mode, index: index, version: version, ticket: ticket}})
                .then(function (response) {
                    if (!response || !response.success) throw new Error(response && response.data && response.data.message || config.messages.failed);
                    return response.data;
                });
        }
        function errorMessage(error) {
            return error.responseJSON && error.responseJSON.data && error.responseJSON.data.message || error.message || config.messages.failed;
        }
        function setBusy(value) {
            busy = value;
            models.prop('disabled', value);
            source.prop('disabled', value);
            refresh();
        }
        function resetHistory() {
            historyPreview = null;
            historyConfirm.prop('hidden', true);
            historyList.empty();
            historyStatus.text('');
        }
        function renderHistory(data) {
            historyList.empty();
            var states = {current: 'historyCurrent', available: 'historyAvailable', blocked: 'historyBlocked', busy: 'historyBusy'};
            data.versions.forEach(function (item) {
                var row = $('<li>').addClass('sakura-costume-version').attr('data-version', item.version);
                var info = $('<div>').appendTo(row);
                $('<strong>').text(config.messages[states[item.state] || 'historyBlocked']).appendTo(info);
                var details = $('<details>').addClass('sakura-costume-version-id').appendTo(info);
                $('<summary>').text(item.version.slice(0, 12) + '…').appendTo(details);
                $('<code>').text(item.version).appendTo(details);
                if (item.files !== null) $('<span>').addClass('sakura-costume-description')
                    .text(message('historySize', item.files, (item.bytes / 1048576).toFixed(2))).appendTo(info);
                if (item.state === 'available') $('<button type="button">').addClass('button')
                    .text(config.messages.historyPreview).on('click', function () { previewHistory(item.version); }).appendTo(row);
                row.appendTo(historyList);
            });
            historyStatus.text(data.skipped ? config.messages.historySkipped : data.versions.length ? '' : config.messages.historyEmpty);
        }
        historyLoad.on('click', async function () {
            if (busy) return;
            resetHistory();
            task = 'history';
            historyStatus.text(config.messages.historyLoading);
            setBusy(true);
            try { renderHistory(await request(selectedModel(), 'history')); }
            catch (error) { historyStatus.text(errorMessage(error)); }
            finally { setBusy(false); }
        });
        async function previewHistory(version) {
            if (busy) return;
            historyPreview = null;
            historyConfirm.prop('hidden', true);
            task = 'history';
            historyStatus.text(config.messages.historyLoading);
            setBusy(true);
            try {
                var data = await request(selectedModel(), 'history-preview', null, version);
                historyPreview = data;
                $('#sakura-costume-history-preview').empty().append($('<code>').text(data.version), $('<span>')
                    .text(message('historySize', data.files, (data.bytes / 1048576).toFixed(2))));
                historyStatus.text('');
                historyConfirm.prop('hidden', false);
            } catch (error) { historyStatus.text(errorMessage(error)); }
            finally { setBusy(false); }
            if (historyPreview) $('#sakura-costume-history-cancel').trigger('focus');
        }
        $('#sakura-costume-history-cancel').on('click', function () {
            if (busy) return;
            historyPreview = null;
            historyConfirm.prop('hidden', true);
            historyLoad.trigger('focus');
        });
        $('#sakura-costume-history-delete').on('click', async function () {
            if (busy || !historyPreview) return;
            var model = selectedModel();
            var preview = historyPreview;
            historyPreview = null;
            task = 'history';
            historyStatus.text(config.messages.historyDeleting);
            setBusy(true);
            try {
                await request(model, 'history-delete', null, preview.version, preview.ticket);
                historyList.find('[data-version="' + preview.version + '"]').remove();
                historyStatus.text(config.messages.historyDeleted);
            } catch (error) {
                historyList.empty();
                historyStatus.text(errorMessage(error));
            } finally {
                historyConfirm.prop('hidden', true);
                setBusy(false);
                historyLoad.trigger('focus');
            }
        });
        stop.on('click', function () {
            paused = true;
            views[selectedModel()].state = 'pausing';
            views[selectedModel()].text = config.messages.pausing;
            refresh();
        });
        download.add(restart).on('click', async function (event) {
            if (busy) return;
            resetHistory();
            var model = selectedModel();
            var view = views[model] = {state: 'checking', text: config.messages.checking};
            task = 'download';
            paused = false;
            setBusy(true);
            try {
                var status = await request(model, event.currentTarget.id === 'sakura-costume-restart' ? 'restart' : 'status');
                var parts = status.parts || {};
                view.metrics = {completed: status.total - status.missing.length, total: status.total, bytes: status.bytes, size: status.size};
                view.state = paused ? 'pausing' : status.refresh ? 'overwriting' : 'downloading';
                view.text = config.messages[paused ? 'pausing' : view.state];
                refresh();
                for (var i = 0; i < status.missing.length && !paused; i++) {
                    var index = status.missing[i];
                    var part;
                    do {
                        part = await request(model, 'download', index);
                        // The baseline includes existing parts, including parts for later files.
                        view.metrics.bytes += part.bytes - (parts[index] || 0);
                        parts[index] = part.bytes;
                        if (part.complete) view.metrics.completed++;
                        view.metrics.chunk = message('chunk', (part.bytes / 1024).toFixed(1), (part.size / 1024).toFixed(1));
                        refresh();
                    } while (!part.complete && !paused);
                }
                if (paused) {
                    view.state = 'paused';
                    view.text = config.messages.paused;
                } else {
                    view.state = 'verifying';
                    view.text = config.messages.verifying;
                    view.metrics.chunk = '';
                    refresh();
                    await request(model, 'finish');
                    config.models[model].ready = true;
                    source.val('local');
                    view.state = 'complete';
                    view.text = config.messages.complete;
                    view.metrics.bytes = view.metrics.size;
                    view.metrics.completed = view.metrics.total;
                }
            } catch (error) {
                view.state = 'failed';
                view.text = errorMessage(error);
            } finally {
                setBusy(false);
            }
        });
        clear.on('click', async function () {
            if (busy) return;
            resetHistory();
            var model = selectedModel();
            var view = views[model] || {};
            views[model] = view;
            view.state = 'clearing';
            view.text = config.messages.clearing;
            task = 'clear';
            setBusy(true);
            try {
                await request(model, 'clear');
                view.state = config.models[model].ready ? 'ready' : 'idle';
                view.text = config.messages.cleared;
                view.metrics = null;
            } catch (error) {
                view.state = 'failed';
                view.text = errorMessage(error);
            } finally {
                setBusy(false);
            }
        });
        // Disabled fields must not disappear from a form saved during a download.
        source.closest('form').on('submit', function (event) { if (busy) event.preventDefault(); });
        models.on('change', function () { resetHistory(); refresh(); });
        source.on('change', function () { resetHistory(); refresh(); });
        refresh();
    });
}(window, window.jQuery));

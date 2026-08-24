(function (window) {
    'use strict';

    if (typeof window.APlayer !== 'function' || !window.APlayer.prototype.notice) {
        return;
    }

    var originalNotice = window.APlayer.prototype.notice;
    var messages = {
        'An audio error has occurred, player will skip forward in 2 seconds.': '音频加载失败，播放器将在 2 秒后尝试下一首。',
        'An audio error has occurred.': '音频加载失败，请稍后重试。',
        'Error: HLS is not supported.': '当前浏览器不支持 HLS 音频。'
    };

    function translateNotice(message) {
        var lyricError = /^LRC file request fails:\s*status\s+(\d+)$/i.exec(message);
        if (lyricError) {
            return '歌词加载失败（状态码：' + lyricError[1] + '）。';
        }

        return messages[message] || message;
    }

    function translateUnavailableLyric(player) {
        window.setTimeout(function () {
            var lines = player.container.querySelectorAll('.aplayer-lrc p');
            for (var i = 0; i < lines.length; i++) {
                if (lines[i].textContent.trim() === 'Not available') {
                    lines[i].textContent = '暂无可用歌词';
                }
            }
        }, 0);
    }

    window.APlayer.prototype.notice = function (message) {
        var originalMessage = String(message);
        var args = Array.prototype.slice.call(arguments);
        args[0] = translateNotice(originalMessage);
        if (/^LRC file request fails:/i.test(originalMessage)) {
            translateUnavailableLyric(this);
        }

        return originalNotice.apply(this, args);
    };
})(window);

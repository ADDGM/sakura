<?php
/** Explicit, previewed retirement of completed managed costume versions. */

function sakura_live2d_costume_history_error($code = 'history')
{
    $messages = array(
        'history' => __('This version cannot be cleaned automatically. Unrecognized or incomplete files were kept.', 'sakura'),
        'current' => __('The current costume version is protected.', 'sakura'),
        'busy' => __('This version is in use. Retry after the download finishes.', 'sakura'),
        'changed' => __('The version changed or the confirmation expired. Preview it again before cleaning.', 'sakura'),
        'cleanup' => __('Cleanup could not finish. Refresh the history list to inspect the remaining files.', 'sakura'),
    );
    return new WP_Error($code, $messages[$code]);
}

/** Resolve only existing, non-linked directories below this site's upload root. */
function sakura_live2d_costume_history_root($model, $version = '')
{
    if (!in_array($model, array('pio', 'tia'), true) ||
        ($version !== '' && !preg_match('/\A[0-9a-f]{64}\z/', $version))) return false;
    $uploads = wp_upload_dir(null, false);
    $base = $uploads['basedir'] ?? '';
    if (!empty($uploads['error']) || !is_string($base) || is_link($base) || !is_dir($base)) return false;
    $path = realpath($base);
    if ($path === false) return false;
    $parts = array('sakura-live2d', 'managed', $model);
    if ($version !== '') $parts[] = $version;
    foreach ($parts as $part) {
        $path .= '/' . $part;
        clearstatcache(true, $path);
        if (is_link($path) || !is_dir($path)) return false;
    }
    return $path;
}

/** Flat allowlist only. Unknown content rejects the entire version, never a recursive delete. */
function sakura_live2d_costume_history_scan($path, $version, $hash = false)
{
    if (!sakura_live2d_costume_ready(array('path' => $path), array('packVersion' => $version))) return false;
    $files = array();
    $bytes = 0;
    foreach (array('' => '/\A(?:ready\.json|refresh\.json|\.lock|\.state-[0-9a-f]{24})\z/',
        'textures/' => '/\A(?:[a-z0-9]+(?:-[a-z0-9]+)*\.png|\.part-[0-9a-f]{64}|\.chunk-[0-9a-f]{24})\z/') as $prefix => $pattern) {
        $dir = $path . ($prefix === '' ? '' : '/textures');
        // An empty textures directory may already have been removed by a failed cleanup.
        if ($prefix !== '' && !file_exists($dir) && !is_link($dir)) continue;
        if (is_link($dir) || !is_dir($dir)) return false;
        try { $entries = new DirectoryIterator($dir); } catch (UnexpectedValueException $e) { return false; }
        foreach ($entries as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();
            if ($prefix === '' && $name === 'textures' && !$entry->isLink() && $entry->isDir()) continue;
            if ($entry->isLink() || !$entry->isFile() || !preg_match($pattern, $name) || strlen($name) > 80 || count($files) >= 1024) return false;
            $file = $dir . '/' . $name;
            clearstatcache(true, $file);
            $stat = @lstat($file);
            if (!$stat || $stat['nlink'] !== 1 || $stat['size'] > 2097152 || ($bytes += $stat['size']) > 629145600) return false;
            if ($name === '.lock' && $stat['size'] !== 0) return false;
            // Windows locks can deny reads through a second handle; the lock is always empty.
            $digest = $hash && $name !== '.lock' ? @hash_file('sha256', $file) : '';
            if ($digest === false) return false;
            $files[$prefix . $name] = array($stat['size'], $stat['mtime'], $stat['ctime'], $stat['ino'], $digest);
        }
    }
    // Every completed download made by this feature has a lock file.
    if (!isset($files['.lock'], $files['ready.json'])) return false;
    ksort($files);
    return array('files' => $files, 'bytes' => $bytes, 'fingerprint' => hash('sha256', wp_json_encode($files)));
}

function sakura_live2d_costume_history_lock($path)
{
    $file = $path . '/.lock';
    if (is_link($file) || !is_file($file)) return false;
    $lock = @fopen($file, 'r+');
    if (!$lock) return false;
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return false; }
    return $lock;
}

/** No paths or filenames leave the server. Listing neither creates directories nor hashes PNGs. */
function sakura_live2d_costume_history_list($catalog)
{
    $root = sakura_live2d_costume_history_root($catalog['model']['id']);
    $result = array('versions' => array(), 'skipped' => 0);
    if (!$root) return $result;
    try { $entries = new DirectoryIterator($root); } catch (UnexpectedValueException $e) { return sakura_live2d_costume_history_error(); }
    foreach ($entries as $entry) {
        if ($entry->isDot()) continue;
        $version = $entry->getFilename();
        if (!preg_match('/\A[0-9a-f]{64}\z/', $version) || count($result['versions']) >= 100) { $result['skipped']++; continue; }
        $path = sakura_live2d_costume_history_root($catalog['model']['id'], $version);
        $snapshot = $path ? sakura_live2d_costume_history_scan($path, $version) : false;
        $state = $version === $catalog['packVersion'] ? 'current' : 'blocked';
        if ($state !== 'current' && $snapshot) {
            $lock = sakura_live2d_costume_history_lock($path);
            $state = $lock ? 'available' : 'busy';
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
        $result['versions'][] = array('version' => $version, 'state' => $state,
            'files' => $snapshot ? count($snapshot['files']) : null, 'bytes' => $snapshot ? $snapshot['bytes'] : null);
    }
    usort($result['versions'], static function ($a, $b) {
        if (($a['state'] === 'current') !== ($b['state'] === 'current')) return $a['state'] === 'current' ? -1 : 1;
        return strcmp($a['version'], $b['version']);
    });
    return $result;
}

function sakura_live2d_costume_history_request($model, $mode, $version = '', $ticket = '')
{
    $catalog = sakura_live2d_costume_catalog($model);
    if (!$catalog || !in_array($mode, array('history', 'history-preview', 'history-delete'), true)) return sakura_live2d_costume_history_error();
    if ($mode === 'history') return sakura_live2d_costume_history_list($catalog);
    if ($version === $catalog['packVersion']) return sakura_live2d_costume_history_error('current');
    $path = sakura_live2d_costume_history_root($model, $version);
    if ($version === '' || !$path || !sakura_live2d_costume_history_scan($path, $version)) return sakura_live2d_costume_history_error();
    $lock = sakura_live2d_costume_history_lock($path);
    if (!$lock) return sakura_live2d_costume_history_error('busy');
    try {
        clearstatcache();
        if (sakura_live2d_costume_history_root($model, $version) !== $path) return sakura_live2d_costume_history_error();
        $snapshot = sakura_live2d_costume_history_scan($path, $version, true);
        if (!$snapshot) return sakura_live2d_costume_history_error();
        $action = 'sakura-costume-history:' . $model . ':' . $version . ':' . $snapshot['fingerprint'];
        if ($mode === 'history-preview') return array('version' => $version, 'files' => count($snapshot['files']),
            'bytes' => $snapshot['bytes'], 'ticket' => wp_create_nonce($action));
        if (!is_string($ticket) || !wp_verify_nonce($ticket, $action)) return sakura_live2d_costume_history_error('changed');
        // Keep the completion marker until payload removal succeeds, so partial failures can be previewed again.
        foreach ($snapshot['files'] as $name => $metadata) {
            if ($name === '.lock' || $name === 'ready.json') continue;
            if (!@unlink($path . '/' . $name)) return sakura_live2d_costume_history_error('cleanup');
        }
        if (is_dir($path . '/textures') && !@rmdir($path . '/textures')) return sakura_live2d_costume_history_error('cleanup');
        if (!@unlink($path . '/ready.json')) return sakura_live2d_costume_history_error('cleanup');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    // Windows cannot unlink an open lock. Only remove the empty shell after releasing it;
    // a concurrent writer causes rmdir to fail and its new content is never traversed.
    if (!@unlink($path . '/.lock') || !@rmdir($path)) return sakura_live2d_costume_history_error('cleanup');
    return array('version' => $version, 'files' => count($snapshot['files']), 'bytes' => $snapshot['bytes']);
}

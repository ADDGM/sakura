<?php
/** Model-bound costume catalogs and opt-in local downloads. */
require_once __DIR__ . '/live2d-costume-history.php';

/** Preserve existing installations until an explicit source is saved. */
function sakura_live2d_costume_source()
{
    $source = akina_option('live2d_costume_source', '');
    if (in_array($source, array('cdn', 'local', 'bundled', 'custom', 'remote'), true)) return $source;
    if (in_array(akina_option('live2d_b', false), array(true, 1, '1'), true) && trim((string) akina_option('live2d_i', '')) !== '') return 'remote';
    return trim((string) akina_option('live2d_catalog', '')) !== '' ? 'custom' : 'cdn';
}

/** Read only the small catalog shipped with the theme, never an arbitrary URL. */
function sakura_live2d_costume_catalog($model)
{
    static $catalogs = array();
    if (!in_array($model, array('pio', 'tia'), true)) return null;
    if (array_key_exists($model, $catalogs)) return $catalogs[$model];
    $catalogs[$model] = null;
    $root = get_template_directory();
    $file = $root . '/live2d/costumes/' . $model . '/catalog.json';
    if (!is_file($file) || !is_readable($file) || filesize($file) > 65536) return null;
    $data = json_decode((string) @file_get_contents($file), true);
    if (!is_array($data) || ($data['version'] ?? null) !== 1 ||
        !is_string($data['packVersion'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/', $data['packVersion']) ||
        ($data['model']['id'] ?? null) !== $model ||
        ($data['model']['sha256'] ?? null) !== @hash_file('sha256', $root . '/live2d/model/' . $model . '/model.moc') ||
        !is_array($data['costumes'] ?? null) || count($data['costumes']) < 1 || count($data['costumes']) > 256) return null;
    $ids = $hashes = array();
    foreach ($data['costumes'] as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) ||
            !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $item['id']) || strlen($item['id']) > 64 ||
            ($item['texture'] ?? null) !== 'textures/' . $item['id'] . '.png' ||
            !is_int($item['bytes'] ?? null) || $item['bytes'] < 24 || $item['bytes'] > 2097152 ||
            !is_string($item['sha256'] ?? null) || !preg_match('/\A[0-9a-f]{64}\z/', $item['sha256']) ||
            isset($ids[$item['id']]) || isset($hashes[$item['sha256']])) return null;
        $ids[$item['id']] = true;
        $hashes[$item['sha256']] = true;
    }
    $identity = array('model' => $data['model'], 'costumes' => $data['costumes']);
    if (!hash_equals($data['packVersion'], hash('sha256', wp_json_encode($identity, JSON_UNESCAPED_SLASHES)))) return null;
    $catalogs[$model] = $data;
    return $data;
}

function sakura_live2d_costume_cdn_base($catalog)
{
    $ref = sakura_remote_resource_ref();
    return $ref === '' ? '' : 'https://cdn.jsdelivr.net/gh/ADDGM/sakura@' . $ref . '/live2d/costumes/' . $catalog['model']['id'] . '/';
}

/** Resolve a controlled version directory; merely viewing settings never creates it. */
function sakura_live2d_costume_directory($catalog, $create = false)
{
    $uploads = wp_upload_dir(null, false);
    if (!empty($uploads['error'])) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    $base = $uploads['basedir'];
    if (!is_dir($base) && (!$create || !wp_mkdir_p($base))) return null;
    $base = realpath($base);
    if ($base === false) return null;
    $relative = 'sakura-live2d/managed/' . $catalog['model']['id'] . '/' . $catalog['packVersion'];
    $dir = $base;
    foreach (explode('/', $relative . '/textures') as $part) {
        $dir .= '/' . $part;
        if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
        if (!is_dir($dir) && (!$create || !@mkdir($dir, 0755))) return null;
    }
    return array('path' => dirname($dir), 'url' => trailingslashit($uploads['baseurl']) . $relative . '/');
}

function sakura_live2d_costume_file_valid($file, $item)
{
    clearstatcache(true, $file);
    if (is_link($file) || !is_file($file) || !is_readable($file) || @filesize($file) !== $item['bytes']) return false;
    $hash = @hash_file('sha256', $file);
    if (!is_string($hash) || !hash_equals($item['sha256'], $hash)) return false;
    $image = @getimagesize($file);
    return is_array($image) && $image[0] === 1024 && $image[1] === 1024 && $image[2] === IMAGETYPE_PNG;
}

function sakura_live2d_costume_ready($directory, $catalog)
{
    if (!$directory || is_wp_error($directory)) return false;
    $file = $directory['path'] . '/ready.json';
    return !is_link($file) && is_file($file) && is_readable($file) && @filesize($file) < 256 &&
        json_decode((string) @file_get_contents($file), true) === array('packVersion' => $catalog['packVersion']);
}

/** No network request and no PNG prefetch on frontend page views. */
function sakura_live2d_costume_urls($model, $source)
{
    if (!in_array($source, array('cdn', 'local'), true)) return array();
    $catalog = sakura_live2d_costume_catalog($model);
    if (!$catalog) return array();
    if ($source === 'local') {
        $directory = sakura_live2d_costume_directory($catalog);
        $base = sakura_live2d_costume_ready($directory, $catalog) ? $directory['url'] : '';
    } else $base = sakura_live2d_costume_cdn_base($catalog);
    if ($base === '') return array();
    return array_map(static function ($item) use ($base) { return esc_url_raw($base . $item['texture']); }, $catalog['costumes']);
}

function sakura_live2d_costume_write_state($file, $value)
{
    if (is_link($file) || (file_exists($file) && !is_file($file))) return false;
    $temp = dirname($file) . '/.state-' . bin2hex(random_bytes(12));
    $json = wp_json_encode($value);
    try { return @file_put_contents($temp, $json, LOCK_EX) === strlen($json) && @rename($temp, $file); }
    finally { if (is_file($temp) && !is_link($temp)) @unlink($temp); }
}

/** Null means ordinary download; a list records completed overwrite replacements. */
function sakura_live2d_costume_refresh_state($directory, $catalog)
{
    if (!$directory) return null;
    $file = $directory['path'] . '/refresh.json';
    if (!file_exists($file) && !is_link($file)) return null;
    if (!is_link($file) && is_file($file) && @filesize($file) < 4096) {
        $data = json_decode((string) @file_get_contents($file), true);
        if (is_array($data) && ($data['packVersion'] ?? '') === $catalog['packVersion'] && is_array($data['done'] ?? null)) {
            $done = $data['done'];
            if (count($done) <= count($catalog['costumes']) && count(array_unique($done, SORT_REGULAR)) === count($done)) {
                foreach ($done as $index) if (!is_int($index) || !isset($catalog['costumes'][$index])) return new WP_Error('state', __('Download state is invalid. Clear the unfinished download and retry.', 'sakura'));
                return $data;
            }
        }
    }
    return new WP_Error('state', __('Download state is invalid. Clear the unfinished download and retry.', 'sakura'));
}

function sakura_live2d_costume_part_size($file, $item)
{
    clearstatcache(true, $file);
    if (!file_exists($file) && !is_link($file)) return 0;
    if (is_link($file) || !is_file($file)) return -1;
    $size = @filesize($file);
    return is_int($size) && $size <= $item['bytes'] ? $size : -1;
}

/** One 256 KiB range per request; a verified whole-file response is also accepted. */
function sakura_live2d_costume_download($catalog, $directory, $index, $force = false)
{
    if (!isset($catalog['costumes'][$index])) return new WP_Error('index', __('Invalid costume download request.', 'sakura'));
    $item = $catalog['costumes'][$index];
    $file = $directory['path'] . '/' . $item['texture'];
    $complete = array('complete' => true, 'bytes' => $item['bytes'], 'size' => $item['bytes']);
    if (!$force && sakura_live2d_costume_file_valid($file, $item)) return $complete;
    if (is_link($file) || (file_exists($file) && !is_file($file))) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    $base = sakura_live2d_costume_cdn_base($catalog);
    if ($base === '') return new WP_Error('source', __('This build has no fixed CDN reference. Install a published build to download costumes.', 'sakura'));
    $partial = $directory['path'] . '/textures/.part-' . $item['sha256'];
    $offset = sakura_live2d_costume_part_size($partial, $item);
    if ($offset < 0) return new WP_Error('state', __('Download state is invalid. Clear the unfinished download and retry.', 'sakura'));
    $temp = $directory['path'] . '/textures/.chunk-' . bin2hex(random_bytes(12));
    $handle = @fopen($temp, 'x+b');
    if (!$handle) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    fclose($handle);
    try {
        if ($offset < $item['bytes']) {
            $end = min($offset + 262144, $item['bytes']) - 1;
            $response = wp_safe_remote_get($base . $item['texture'], array(
                'timeout' => 20, 'redirection' => 0, 'stream' => true, 'filename' => $temp,
                'headers' => array('Range' => 'bytes=' . $offset . '-' . $end, 'Accept-Encoding' => 'identity'),
                // A server may ignore Range. The trusted catalog still bounds the whole response.
                'limit_response_size' => $item['bytes'] + 1,
            ));
            $status = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
            clearstatcache(true, $temp);
            if ($status === 200) {
                if (!sakura_live2d_costume_file_valid($temp, $item)) return new WP_Error('integrity', __('The downloaded costume failed integrity checks. Retry the download.', 'sakura'));
                if (!@rename($temp, $partial)) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
                $offset = $item['bytes'];
            } elseif ($status === 206) {
                $range = trim((string) wp_remote_retrieve_header($response, 'content-range'));
                if ($range !== 'bytes ' . $offset . '-' . $end . '/' . $item['bytes'] || @filesize($temp) !== $end - $offset + 1) {
                    return new WP_Error('range', __('The server returned an invalid download chunk. Retry to resume.', 'sakura'));
                }
                $input = @fopen($temp, 'rb');
                $output = @fopen($partial, 'ab');
                if (!$input || !$output) {
                    if ($input) fclose($input);
                    if ($output) fclose($output);
                    return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
                }
                $written = @stream_copy_to_stream($input, $output);
                fclose($input); fclose($output);
                if ($written !== $end - $offset + 1) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
                $offset += $written;
            } else return new WP_Error('download', __('The costume download failed. Check the server connection and retry to resume.', 'sakura'));
        }
        if ($offset < $item['bytes']) return array('complete' => false, 'bytes' => $offset, 'size' => $item['bytes']);
        if (!sakura_live2d_costume_file_valid($partial, $item)) {
            @unlink($partial);
            return new WP_Error('integrity', __('The downloaded costume failed integrity checks. Retry the download.', 'sakura'));
        }
        if (!@rename($partial, $file)) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
        @chmod($file, 0644);
        return $complete;
    } finally {
        if (is_file($temp) && !is_link($temp)) @unlink($temp);
    }
}

/** Verify actual files on resume and once more before activating a complete version. */
function sakura_live2d_costume_status($catalog, $directory)
{
    $refresh = sakura_live2d_costume_refresh_state($directory, $catalog);
    if (is_wp_error($refresh)) return $refresh;
    $missing = array();
    $parts = array();
    $received = 0;
    foreach ($catalog['costumes'] as $index => $item) {
        if (!$directory || ($refresh !== null && !in_array($index, $refresh['done'], true)) ||
            !sakura_live2d_costume_file_valid($directory['path'] . '/' . $item['texture'], $item)) {
            $missing[] = $index;
            $parts[$index] = $directory ? max(0, sakura_live2d_costume_part_size($directory['path'] . '/textures/.part-' . $item['sha256'], $item)) : 0;
            $received += $parts[$index];
        } else $received += $item['bytes'];
    }
    return array('total' => count($catalog['costumes']), 'missing' => $missing, 'parts' => $parts,
        'bytes' => $received, 'size' => array_sum(array_column($catalog['costumes'], 'bytes')), 'refresh' => $refresh !== null,
        'ready' => count($missing) === 0 && sakura_live2d_costume_ready($directory, $catalog));
}

/** Clear only known files in this managed version, retaining an activated library. */
function sakura_live2d_costume_clear($catalog, $directory)
{
    $keepInstalled = sakura_live2d_costume_ready($directory, $catalog);
    $files = array($directory['path'] . '/refresh.json');
    foreach ($catalog['costumes'] as $item) {
        $files[] = $directory['path'] . '/textures/.part-' . $item['sha256'];
        if (!$keepInstalled) $files[] = $directory['path'] . '/' . $item['texture'];
    }
    foreach (array_merge(glob($directory['path'] . '/textures/.chunk-*') ?: array(), glob($directory['path'] . '/.state-*') ?: array()) as $temp) {
        if (preg_match('/\A\.(?:chunk|state)-[0-9a-f]{24}\z/', basename($temp))) $files[] = $temp;
    }
    foreach ($files as $file) {
        if (is_link($file) || (file_exists($file) && (!is_file($file) || !@unlink($file)))) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    }
    return true;
}

function sakura_live2d_costume_request($model, $mode, $index = null)
{
    $catalog = sakura_live2d_costume_catalog($model);
    if (!$catalog || !in_array($mode, array('status', 'download', 'finish', 'restart', 'clear'), true)) return new WP_Error('request', __('No compatible extension costumes are available for this model. Bundled costumes remain available.', 'sakura'));
    $directory = sakura_live2d_costume_directory($catalog, $mode !== 'status');
    if (is_wp_error($directory)) return $directory;
    if ($mode === 'status') return sakura_live2d_costume_status($catalog, $directory);
    if (!$directory) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    $lockFile = $directory['path'] . '/.lock';
    if (is_link($lockFile)) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    $lock = @fopen($lockFile, 'c');
    if (!$lock) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return new WP_Error('busy', __('Another costume download is in progress. Please retry shortly.', 'sakura'));
    }
    try {
        if ($mode === 'clear') return sakura_live2d_costume_clear($catalog, $directory);
        $refresh = sakura_live2d_costume_refresh_state($directory, $catalog);
        if (is_wp_error($refresh)) return $refresh;
        if ($mode === 'restart') {
            if ($refresh === null && !sakura_live2d_costume_write_state($directory['path'] . '/refresh.json', array('packVersion' => $catalog['packVersion'], 'done' => array()))) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
            return sakura_live2d_costume_status($catalog, $directory);
        }
        if ($mode === 'download') {
            $result = sakura_live2d_costume_download($catalog, $directory, $index, $refresh !== null && !in_array($index, $refresh['done'], true));
            if (!is_wp_error($result) && $result['complete'] && $refresh !== null && !in_array($index, $refresh['done'], true)) {
                $refresh['done'][] = $index;
                if (!sakura_live2d_costume_write_state($directory['path'] . '/refresh.json', $refresh)) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
            }
            return $result;
        }
        $status = sakura_live2d_costume_status($catalog, $directory);
        if (is_wp_error($status)) return $status;
        if ($status['missing']) return new WP_Error('incomplete', __('The costume library is incomplete. Resume the download before using local costumes.', 'sakura'));
        $ready = $directory['path'] . '/ready.json';
        if (!sakura_live2d_costume_write_state($ready, array('packVersion' => $catalog['packVersion']))) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
        if ($refresh !== null && !@unlink($directory['path'] . '/refresh.json')) return new WP_Error('storage', __('The costume storage directory is unavailable.', 'sakura'));
        $status['ready'] = true;
        $status['refresh'] = false;
        return $status;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function sakura_live2d_costume_ajax()
{
    if (!current_user_can('edit_theme_options') || !isset($_REQUEST['nonce']) || !is_string($_REQUEST['nonce']) ||
        !check_ajax_referer('sakura-live2d-costumes', 'nonce', false)) {
        wp_send_json_error(array('message' => __('You cannot manage costume downloads. Reload the settings page and try again.', 'sakura')), 403);
        return;
    }
    $model = isset($_POST['model']) && is_string($_POST['model']) ? wp_unslash($_POST['model']) : '';
    $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? wp_unslash($_POST['mode']) : '';
    $index = isset($_POST['index']) && is_string($_POST['index']) && preg_match('/\A[0-9]{1,3}\z/', $_POST['index']) ? (int) $_POST['index'] : null;
    if (in_array($mode, array('history', 'history-preview', 'history-delete'), true)) {
        $version = isset($_POST['version']) && is_string($_POST['version']) ? wp_unslash($_POST['version']) : '';
        $ticket = isset($_POST['ticket']) && is_string($_POST['ticket']) ? wp_unslash($_POST['ticket']) : '';
        $result = sakura_live2d_costume_history_request($model, $mode, $version, $ticket);
    } else $result = sakura_live2d_costume_request($model, $mode, $index);
    if (is_wp_error($result)) wp_send_json_error(array('message' => $result->get_error_message()), 400);
    else wp_send_json_success($result);
}
add_action('wp_ajax_sakura_live2d_resources', 'sakura_live2d_costume_ajax');

function sakura_live2d_costume_field()
{
    return '<div id="sakura-costume-panel" class="sakura-costume-panel" data-state="idle">' .
        '<div class="sakura-costume-header"><div><span class="sakura-costume-eyebrow">' . esc_html(__('Model library', 'sakura')) . '</span>' .
        '<strong id="sakura-costume-model"></strong></div><span id="sakura-costume-badge" class="sakura-costume-badge"></span></div>' .
        '<p id="sakura-costume-summary"></p>' .
        '<p class="sakura-costume-description">' . esc_html(__('The compatible library follows the selected model. Downloading is optional; CDN costumes load only when selected. Downloads survive theme updates.', 'sakura')) . '</p>' .
        '<div id="sakura-costume-meter" class="sakura-costume-meter" hidden>' .
        '<div class="sakura-costume-meter-heading"><label for="sakura-costume-bar">' . esc_html(__('Download progress', 'sakura')) . '</label><strong id="sakura-costume-percent"></strong></div>' .
        '<progress id="sakura-costume-bar" max="100" value="0" aria-describedby="sakura-costume-transfer"></progress>' .
        '<div class="sakura-costume-meter-details"><span id="sakura-costume-count"></span><span id="sakura-costume-transfer"></span></div>' .
        '<p id="sakura-costume-chunk" class="sakura-costume-description"></p></div>' .
        '<p id="sakura-costume-progress" class="sakura-costume-status" role="status" aria-live="polite" aria-atomic="true"></p>' .
        '<div class="sakura-costume-actions"><button type="button" class="button button-primary" id="sakura-costume-download" disabled>' . esc_html(__('Download to this site', 'sakura')) . '</button>' .
        '<button type="button" class="button" id="sakura-costume-stop" hidden>' . esc_html(__('Pause download', 'sakura')) . '</button></div>' .
        '<details class="sakura-costume-maintenance"><summary>' . esc_html(__('Download management', 'sakura')) . '</summary>' .
        '<p class="sakura-costume-description">' . esc_html(__('Clear removes unfinished managed downloads only. Overwrite keeps installed files until replacements pass verification. Custom catalogs are unaffected.', 'sakura')) . '</p>' .
        '<div class="sakura-costume-actions"><button type="button" class="button" id="sakura-costume-restart" disabled>' . esc_html(__('Overwrite / resume download', 'sakura')) . '</button>' .
        '<button type="button" class="button sakura-costume-clear" id="sakura-costume-clear" disabled>' . esc_html(__('Clear unfinished download', 'sakura')) . '</button></div>' .
        '<div class="sakura-costume-history"><h5>' . esc_html(__('Version history', 'sakura')) . '</h5>' .
        '<p class="sakura-costume-description">' . esc_html(__('Theme updates keep older libraries. Preview one version before cleaning it; the current version is always protected.', 'sakura')) . '</p>' .
        '<div class="sakura-costume-actions"><button type="button" class="button" id="sakura-costume-history-load">' . esc_html(__('View history', 'sakura')) . '</button></div>' .
        '<p id="sakura-costume-history-status" role="status" aria-live="polite"></p><ul id="sakura-costume-history-list"></ul>' .
        '<div id="sakura-costume-history-confirm" class="sakura-costume-confirm" role="group" aria-labelledby="sakura-costume-history-heading" hidden>' .
        '<strong id="sakura-costume-history-heading">' . esc_html(__('Confirm version cleanup', 'sakura')) . '</strong><p id="sakura-costume-history-preview"></p>' .
        '<p class="sakura-costume-description">' . esc_html(__('This removes the selected local copy. Any custom links to this version will stop working. It does not remove CDN resources.', 'sakura')) . '</p>' .
        '<div class="sakura-costume-actions"><button type="button" class="button sakura-costume-clear" id="sakura-costume-history-delete">' . esc_html(__('Confirm cleanup', 'sakura')) . '</button>' .
        '<button type="button" class="button" id="sakura-costume-history-cancel">' . esc_html(__('Cancel', 'sakura')) . '</button></div></div></div></details>' .
        '<noscript>' . esc_html(__('Enable JavaScript to manage costume downloads.', 'sakura')) . '</noscript></div>';
}

function sakura_live2d_costume_admin_scripts($hook)
{
    if ($hook !== 'appearance_page_options-framework') return;
    $models = array();
    foreach (array('tia', 'pio') as $model) {
        $catalog = sakura_live2d_costume_catalog($model);
        $directory = $catalog ? sakura_live2d_costume_directory($catalog) : null;
        $models[$model] = array('count' => $catalog ? count($catalog['costumes']) : 0,
            'bytes' => $catalog ? array_sum(array_column($catalog['costumes'], 'bytes')) : 0,
            'downloadable' => $catalog && sakura_live2d_costume_cdn_base($catalog) !== '',
            'ready' => $catalog && sakura_live2d_costume_ready($directory, $catalog));
    }
    wp_enqueue_style('sakura-live2d-resources', sakura_theme_asset_url('inc/css/live2d-costumes.css'), array('optionsframework'), sakura_frontend_cache_version());
    wp_enqueue_script('sakura-live2d-resources', sakura_theme_asset_url('inc/js/live2d-costumes.js'), array('jquery'), sakura_frontend_cache_version(), true);
    wp_localize_script('sakura-live2d-resources', 'SakuraCostumeAdmin', array(
        'ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sakura-live2d-costumes'), 'models' => $models,
        'messages' => array(
            'library' => __('%1$s extension costumes, %2$s MiB. Three bundled costumes are always included.', 'sakura'),
            'empty' => __('No compatible extension costumes are available for this model. Bundled costumes remain available.', 'sakura'),
            'unavailable' => __('This build has no fixed CDN reference. Install a published build to download costumes.', 'sakura'),
            'checking' => __('Checking saved costumes...', 'sakura'),
            'progress' => __('Downloaded and verified %1$s of %2$s costumes.', 'sakura'),
            'complete' => __('Download complete. Local costumes selected; save the settings to apply.', 'sakura'),
            'paused' => __('Download paused. Click download to resume.', 'sakura'),
            'failed' => __('The costume download failed. Check the server connection and retry to resume.', 'sakura'),
            'notReady' => __('The costume library is incomplete. Resume the download before using local costumes.', 'sakura'),
            'ready' => __('The local costume library is ready.', 'sakura'),
            'chunk' => __('Current file: %1$s / %2$s KiB received.', 'sakura'),
            'cleared' => __('Unfinished download cleared. Installed and custom costumes were kept.', 'sakura'),
            'download' => __('Download to this site', 'sakura'),
            'resume' => __('Resume download', 'sakura'),
            'useLocal' => __('Use local costumes', 'sakura'),
            'cdn' => __('CDN on demand', 'sakura'),
            'available' => __('Local library ready', 'sakura'),
            'idle' => __('Not downloaded', 'sakura'),
            'noLibrary' => __('Bundled costumes only', 'sakura'),
            'downloading' => __('Downloading', 'sakura'),
            'overwriting' => __('Overwriting', 'sakura'),
            'pausing' => __('Pausing after the current request...', 'sakura'),
            'pausedLabel' => __('Paused', 'sakura'),
            'failedLabel' => __('Download interrupted', 'sakura'),
            'checkingLabel' => __('Checking', 'sakura'),
            'verifying' => __('Verifying the complete library...', 'sakura'),
            'clearing' => __('Clearing unfinished downloads...', 'sakura'),
            'verified' => __('%1$s / %2$s verified', 'sakura'),
            'transferred' => __('%1$s / %2$s MiB', 'sakura'),
            'historyLoading' => __('Reading version history...', 'sakura'),
            'historyEmpty' => __('No managed versions were found for this model.', 'sakura'),
            'historySkipped' => __('Some entries were left untouched because they could not be recognized or the list limit was reached.', 'sakura'),
            'historyCurrent' => __('Current version · protected', 'sakura'),
            'historyAvailable' => __('Older version', 'sakura'),
            'historyBlocked' => __('Unrecognized or incomplete · kept', 'sakura'),
            'historyBusy' => __('In use · kept', 'sakura'),
            'historySize' => __('%1$s files · %2$s MiB', 'sakura'),
            'historyPreview' => __('Preview cleanup', 'sakura'),
            'historyDeleting' => __('Cleaning the selected version...', 'sakura'),
            'historyDeleted' => __('Selected version cleaned. Current and other versions were kept.', 'sakura'),
        ),
    ));
}
add_action('admin_enqueue_scripts', 'sakura_live2d_costume_admin_scripts');

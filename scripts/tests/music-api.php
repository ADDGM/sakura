<?php
/** 音乐接口行为检查：由 wordpress-smoke.php 在真实 WordPress 中调用。 */
(static function () use (&$errors) {
    $configFilter = static function () { return array('id' => 'sakura_music_fixture'); };
    $optionsFilter = static function () {
        return array('aplayer_server' => 'netease', 'aplayer_playlistid' => 'fixture-list', 'aplayer_cookie' => 'fixture-cookie');
    };
    add_filter('pre_option_optionsframework', $configFilter);
    add_filter('pre_option_sakura_music_fixture', $optionsFilter);
    $filters = array();
    try {
        ob_start();
        font_end_js_control();
        $frontend = ob_get_clean();
        if (!preg_match('/mashiro_option\.meting_api_url = (.+);/', $frontend, $matches)) {
            $errors[] = '前台未输出默认音乐地址。';
        } else {
            $template = json_decode($matches[1], true);
            $url = str_replace(array(':server', ':type', ':id'), array('netease', 'playlist', 'fixture-list'), (string) $template);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (($query['server'] ?? '') !== 'netease' || ($query['type'] ?? '') !== 'playlist' || ($query['id'] ?? '') !== 'fixture-list'
                || !isset($query['music_token']) || !sakura_meting_token_is_valid($query['music_token'], 'playlist', 'fixture-list', 'netease')) {
                $errors[] = '前台默认地址未绑定当前歌单签名。';
            }
        }
        $player = new \Sakura\API\Aplayer();
        $cacheKey = new ReflectionMethod($player, 'cache_key');
        $cacheKey->setAccessible(true);
        $resourceUrl = new ReflectionMethod($player, 'resource_url');
        $resourceUrl->setAccessible(true);
        $fixtures = array('playlist' => array(), 'lyric' => '[00:00.00]fixture', 'pic' => 'https://example.test/cover.jpg', 'url' => 'https://example.test/music.mp3');
        foreach (array_keys($fixtures) as $type) {
            foreach (array('fixture-resource', 'different-id') as $id) {
                $hook = 'pre_transient_' . $cacheKey->invoke($player, $type, $id);
                $filter = static function () use (&$fixtures, $type) {
                    if ($fixtures[$type] instanceof Throwable) { throw $fixtures[$type]; }
                    return $fixtures[$type];
                };
                add_filter($hook, $filter);
                $filters[$hook] = $filter;
            }
        }
        $check = static function ($params, $status, $headers = array()) use (&$errors) {
            $request = new WP_REST_Request('GET', '/sakura/v1/meting/aplayer');
            foreach ($params as $key => $value) { $request->set_param($key, $value); }
            foreach ($headers as $key => $value) { $request->set_header($key, $value); }
            ob_start();
            try { $response = meting_aplayer($request); }
            finally { $body = ob_get_clean(); }
            $cache = $response->get_headers()['Cache-Control'] ?? '';
            if ($response->get_status() !== $status) { $errors[] = '音乐接口状态码与授权场景不匹配。'; }
            if ($status >= 400 ? strpos($cache, 'no-store') === false : strpos($cache, 'public, max-age=') !== 0) {
                $errors[] = '音乐接口成功/失败缓存边界不匹配。';
            }
            if ($status === 200 && $params['type'] === 'lyric' && $body !== '[00:00.00]fixture') {
                $errors[] = '音乐歌词文本输出发生变化。';
            }
            if ($status === 301 && !isset($response->get_headers()['Location'])) { $errors[] = '音乐资源重定向地址缺失。'; }
        };
        foreach (array_keys($fixtures) as $type) {
            $params = array('server' => 'netease', 'type' => $type, 'id' => 'fixture-resource');
            $token = sakura_meting_create_token($type, 'fixture-resource', 60, 'netease');
            $params['music_token'] = $token;
            $check($params, in_array($type, array('pic', 'url'), true) ? 301 : 200);
            foreach (array('meting_nonce', '_wpnonce', 'X-WP-Nonce') as $key) {
                $nonce = wp_create_nonce($key === 'meting_nonce' ? $type . '#:fixture-resource' : 'wp_rest');
                foreach (array('', $token) as $candidate) {
                    $old = array_merge($params, array('music_token' => $candidate));
                    if ($key === 'X-WP-Nonce') { $check($old, 403, array($key => $nonce)); }
                    else { $old[$key] = $nonce; $check($old, 403); }
                }
            }
            foreach (array('', 'invalid', sakura_meting_create_token($type, 'fixture-resource', -1, 'netease')) as $candidate) {
                $check(array_merge($params, array('music_token' => $candidate)), 403);
            }
            $check(array_merge($params, array('id' => 'different-id')), 403);
            $check(array_merge($params, array('server' => 'tencent')), 403);
            $check(array_merge($params, array('type' => $type === 'pic' ? 'url' : 'pic')), 403);
            foreach (array('https://example.test/music', 'https://example.test/index.php?rest_route=/sakura/v1/meting/aplayer') as $baseUrl) {
                $url = $resourceUrl->invoke($player, $baseUrl, 'netease', $type, 'fixture-resource');
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                if (($query['server'] ?? '') !== 'netease' || ($query['type'] ?? '') !== $type || ($query['id'] ?? '') !== 'fixture-resource'
                    || !isset($query['music_token']) || isset($query['meting_nonce']) || !sakura_meting_token_is_valid($query['music_token'], $type, 'fixture-resource', 'netease')) {
                    $errors[] = 'Aplayer 资源地址没有携带匹配的站点签名或查询参数。';
                }
            }
        }
        $params = array('server' => 'netease', 'type' => 'url', 'id' => 'fixture-resource', 'music_token' => sakura_meting_create_token('url', 'fixture-resource', 60, 'netease'));
        $fixtures['url'] = '';
        $check($params, 502);
        $fixtures['url'] = new RuntimeException('fixture upstream failure');
        $check($params, 502);
        $restRequest = new WP_REST_Request();
        $restRequest->set_header('X-WP-Nonce', wp_create_nonce('wp_rest'));
        if (!sakura_rest_nonce_is_valid($restRequest)) { $errors[] = '其他 API 共用的 REST nonce 校验被改变。'; }
    } finally {
        foreach ($filters as $hook => $filter) { remove_filter($hook, $filter); }
        remove_filter('pre_option_sakura_music_fixture', $optionsFilter);
        remove_filter('pre_option_optionsframework', $configFilter);
    }
})();

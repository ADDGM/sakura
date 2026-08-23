<?php
/**
 * 在 WP-CLI 已加载 WordPress 和 Sakura 主题后执行基础运行时检查。
 */

if (!function_exists('wp_get_theme')) {
    fwrite(STDERR, "WordPress 尚未加载。\n");
    exit(1);
}

$theme = wp_get_theme();
$errors = array();
$requiredRoutes = array(
    '/sakura/v1/image/cover',
    '/sakura/v1/image/feature',
    '/sakura/v1/cache_search/json',
    '/sakura/v1/meting/aplayer',
);

if (strtolower($theme->get('Name')) !== 'sakura') {
    $errors[] = '当前激活主题不是 Sakura。';
}
if (!current_theme_supports('title-tag')) {
    $errors[] = '主题未启用 title-tag 支持。';
}
foreach (array('akina_setup', 'sakura_scripts', 'DEFAULT_FEATURE_IMAGE', 'convertip', 'push_smilies', 'get_next_thumbnail_url') as $function) {
    if (!function_exists($function)) {
        $errors[] = "缺少主题函数：{$function}";
    }
}

$sourceChecks = array(
    array('inc/classes/Meting.php', 'utf8_encode(', 'Meting 仍使用已弃用的 utf8_encode()。'),
    array('inc/classes/Meting.php', 'public $temp', 'Meting 未声明临时属性。'),
    array('inc/classes/Meting.php', 'X-Real-IP', 'Meting 仍伪造网易云客户端 IP。'),
    array('inc/classes/Aplayer.php', "str_replace('http://m8.'", 'APlayer 仍强制替换网易云 m8 CDN 主机。'),
    array('inc/classes/Aplayer.php', 'music_token', 'APlayer 未生成公开播放器签名令牌。'),
    array('inc/api.php', 'sakura_meting_token_is_valid', 'Meting 未验证公开播放器签名令牌。'),
    array('inc/api.php', 'public, max-age=', 'Meting 未提供公开播放器缓存头。'),
    array('inc/swicher.php', 'meting_api', '前端播放器未注入公开签名令牌 URL。'),
    array('functions.php', 'sakura_set_frontend_cache_headers', '前台页面未按登录状态隔离缓存。'),
    array('tpl/content-thumb.php', 'substr(the_excerpt()', '文章摘要仍把 the_excerpt() 返回值传给 substr()。'),
);
foreach ($sourceChecks as $check) {
    $source = file_get_contents(get_template_directory() . '/' . $check[0]);
    if ($source === false) {
        $invalid = true;
    } elseif ($check[1] === 'utf8_encode(' || $check[1] === 'substr(the_excerpt()' || $check[1] === 'X-Real-IP' || strpos($check[1], "str_replace('http://") === 0) {
        $invalid = strpos($source, $check[1]) !== false;
    } else {
        $invalid = strpos($source, $check[1]) === false;
    }
    if ($invalid) {
        $errors[] = $check[2];
    }
}

$styleSource = file_get_contents(get_template_directory() . '/style.css');
if ($styleSource === false || strpos($styleSource, '.site-main::after') === false) {
    $errors[] = '内容区未清除内部浮动。';
}

if (convertip('invalid-ip') !== 'Unknown') {
    $errors[] = '无效 IP 未返回 Unknown。';
}
if (!is_string(push_smilies())) {
    $errors[] = '表情面板未返回字符串。';
}
$postId = wp_insert_post(array(
    'post_title' => 'Sakura PHP 8 回归文章',
    'post_content' => '没有特色图，也没有下一篇文章。',
    'post_status' => 'publish',
));
if (is_wp_error($postId)) {
    $errors[] = '无法创建 PHP 8 回归文章。';
} else {
    global $post;
    $post = get_post($postId);
    setup_postdata($post);
    if (!is_string(get_next_thumbnail_url())) {
        $errors[] = '没有下一篇文章时缩略图函数未返回回退地址。';
    }
    wp_reset_postdata();
    wp_delete_post($postId, true);
}

if (function_exists('sakura_meting_nonce_is_valid')) {
    $resourceId = '12345';
    $validRequest = new WP_REST_Request('GET', '/sakura/v1/meting/aplayer');
    $validRequest->set_param('meting_nonce', wp_create_nonce('pic#:' . $resourceId));
    if (!sakura_meting_nonce_is_valid($validRequest, 'pic', $resourceId)) {
        $errors[] = '合法的 Meting 资源 nonce 未通过。';
    }

    $invalidRequest = new WP_REST_Request('GET', '/sakura/v1/meting/aplayer');
    $invalidRequest->set_param('meting_nonce', wp_create_nonce('pic#:different-id'));
    if (sakura_meting_nonce_is_valid($invalidRequest, 'pic', $resourceId)) {
        $errors[] = '不匹配资源 ID 的 Meting nonce 被错误接受。';
    }
}

if (function_exists('sakura_meting_create_token') && function_exists('sakura_meting_token_is_valid')) {
    $musicServer = sanitize_key((string) akina_option('aplayer_server', 'netease'));
    $musicToken = sakura_meting_create_token('pic', '12345', 60, $musicServer);
    if (!sakura_meting_token_is_valid($musicToken, 'pic', '12345', $musicServer)) {
        $errors[] = '合法的播放器公开签名令牌未通过。';
    }
    if (sakura_meting_token_is_valid($musicToken, 'pic', 'different-id', $musicServer)) {
        $errors[] = '不匹配资源 ID 的播放器签名令牌被错误接受。';
    }
    if (sakura_meting_token_is_valid($musicToken, 'lyric', '12345', $musicServer)) {
        $errors[] = '不匹配资源类型的播放器签名令牌被错误接受。';
    }
    $tokenParts = explode('.', $musicToken, 2);
    $tokenPayload = json_decode(sakura_meting_base64url_decode($tokenParts[0]), true);
    if (!is_array($tokenPayload)) {
        $errors[] = '播放器签名令牌载荷无法解析。';
    } else {
        $tokenPayload['cfg'] = str_repeat('0', 64);
        $alteredPayload = sakura_meting_base64url_encode(wp_json_encode($tokenPayload));
        $alteredSignature = sakura_meting_base64url_encode(hash_hmac('sha256', $alteredPayload, wp_salt('auth'), true));
        if (sakura_meting_token_is_valid($alteredPayload . '.' . $alteredSignature, 'pic', '12345', $musicServer)) {
            $errors[] = '不匹配音乐配置的播放器签名令牌被错误接受。';
        }
    }
    $expiredToken = sakura_meting_create_token('pic', '12345', -1, $musicServer);
    if (sakura_meting_token_is_valid($expiredToken, 'pic', '12345', $musicServer)) {
        $errors[] = '过期的播放器签名令牌被错误接受。';
    }
    $configuredCookie = (string) akina_option('aplayer_cookie', '');
    if ($configuredCookie !== '' && strpos($musicToken, $configuredCookie) !== false) {
        $errors[] = '播放器签名令牌泄露了网易云 Cookie。';
    }
    $publicHeaders = sakura_meting_public_cache_headers('lyric');
    if (strpos($publicHeaders['Cache-Control'], 'public, max-age=') !== 0 || isset($publicHeaders['Vary'])) {
        $errors[] = '播放器公开缓存头仍依赖 Cookie。';
    }
}

do_action('rest_api_init');
$routes = rest_get_server()->get_routes();
foreach ($requiredRoutes as $route) {
    if (!isset($routes[$route])) {
        $errors[] = "缺少 REST 路由：{$route}";
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo "WordPress 主题烟雾检查通过：{$theme->get('Version')}\n";

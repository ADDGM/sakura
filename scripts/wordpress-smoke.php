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
    array('inc/classes/Meting.php', 'is_http_url', 'Meting 未校验网易云音频 URL。'),
    array('inc/api.php', 'sakura_meting_token_is_valid', 'Meting 未验证公开播放器签名令牌。'),
    array('inc/api.php', 'sakura_meting_legacy_request_present', 'Meting 未识别旧播放器请求。'),
    array('inc/api.php', 'public, max-age=', 'Meting 未提供公开播放器缓存头。'),
    array('inc/swicher.php', 'meting_api', '前端播放器未注入公开签名令牌 URL。'),
    array('functions.php', 'sakura_set_frontend_cache_headers', '前台页面未按登录状态隔离缓存。'),
    array('functions.php', 'aplayer_localization', '前端未加载 APlayer 中文提示兼容脚本。'),
    array('js/aplayer-localization.js', '音频加载失败', 'APlayer 音频错误提示未完成中文化。'),
    array('js/aplayer-localization.js', '歌词加载失败', 'APlayer 歌词错误提示未完成中文化。'),
    array('comments.php', '<!--此栏不可见--></div>', '评论信息容器未在自定义 QQ 字段后稳定闭合。'),
    array('comments.php', 'if ( ! is_user_logged_in() )', '登录用户仍会输出匿名评论头像辅助字段。'),
    array('comments.php', "'submit_field' => '<p class=\"form-submit\"><span class=\"submit-comment-tips\">", '评论提交按钮缺少专用提示容器。'),
    array('comments.php', "esc_attr__( 'Submit comment', 'sakura' )", '评论提交按钮缺少可访问的提示属性。'),
    array('js/sakura-app.js', "off('change.sakuraUpload'", '评论图片上传事件未使用幂等的命名空间绑定。'),
    array('js/sakura-app.js', '<label class="insert-image-tips popup" for="upload-img-file">', '评论图片上传按钮未使用可访问的 label 触发文件控件。'),
    array('tpl/content-thumb.php', 'substr(the_excerpt()', '文章摘要仍把 the_excerpt() 返回值传给 substr()。'),
    array('inc/theme_plus.php', '$dis = \'\';', '视频显示变量未初始化。'),
    array('inc/theme_plus.php', 'esc_url($ava)', '登录头像未使用已解析的头像地址。'),
    array('inc/theme_plus.php', 'this.onerror=null', '登录头像未配置本地失败回退。'),
    array('js/sakura-app.js', 'document.getElementById("add_post_time")', '无限滚动仍未使用标准 DOM API。'),
    array('js/sakura-app.js', 'if (document.readyState === \'loading\')', '播放器未兼容脚本加载时序。'),
    array('js/sakura-app.js', '    aplayerF();', '播放器未执行移动端兼容初始化。'),
    array('inc/css/optionsframework.css', '@media (max-width: 782px)', '主题设置页缺少移动端响应式规则。'),
    array('inc/css/optionsframework.css', '#optionsframework input.of-radio', '主题设置页未隔离普通单选控件样式。'),
    array('inc/css/optionsframework.css', '#optionsframework .of-radio-option', '主题设置页普通单选控件缺少独立布局规则。'),
    array('inc/options-interface.php', 'class="of-radio-option"', '主题设置页普通单选控件缺少选项包装。'),
    array('inc/options-interface.php', 'class="of-multicheck-option"', '主题设置页多选控件缺少选项包装。'),
    array('inc/options-framework.php', "defined( 'SAKURA_VERSION' ) ? SAKURA_VERSION : false", '主题设置页资源未使用主题版本控制缓存。'),
    array('style.css', '.site-top .lower nav > .menu > ul', '中等宽度导航未区分顶级菜单和下拉菜单。'),
    array('style.css', '.submit-comment-tips:focus-within', '评论提交按钮缺少键盘焦点提示样式。'),
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
$commentsSource = file_get_contents(get_template_directory() . '/comments.php');
if ($commentsSource === false || strpos($commentsSource, 'submit-comment-tips popup') !== false) {
    $errors[] = '评论提交容器仍依赖通用 popup 布局类。';
}
if ($styleSource === false || strpos($styleSource, '.site-main::after') === false) {
    $errors[] = '内容区未清除内部浮动。';
}
if ($styleSource === false || preg_match('/\.insert-image-button\s*\{[^}]*translateY/s', $styleSource)) {
    $errors[] = '评论图片上传控件仍通过位移覆盖其他控件。';
}
if ($styleSource === false || strpos($styleSource, 'width: calc(98% - 46px)') !== false) {
    $errors[] = '评论提交按钮仍依赖固定宽度为上传控件让位。';
}
if ($styleSource === false || strpos($styleSource, '.submit-comment-tips .submit-comment-popuptext') === false) {
    $errors[] = '评论提交提示仍依赖通用 popup 后代选择器。';
}
if ($styleSource === false || !preg_match('/\.comment-respond input\[type="submit"\]\s*\{[^}]*display:\s*block;[^}]*width:\s*100%;/s', $styleSource)) {
    $errors[] = '评论提交按钮未填满提交区域。';
}
$optionsStyleSource = file_get_contents(get_template_directory() . '/inc/css/optionsframework.css');
if ($optionsStyleSource === false || preg_match('/input\[type=checkbox\]\s*,\s*input\[type=radio\]/', $optionsStyleSource)) {
    $errors[] = '主题设置页仍使用旧规则全局覆盖 WordPress 单选控件。';
}
$scriptSource = file_get_contents(get_template_directory() . '/js/sakura-app.js');
if ($scriptSource === false || preg_match('/\baddComment\.I\s*\(/', $scriptSource)) {
    $errors[] = '主题脚本仍在自定义评论对象外部依赖 addComment.I。';
}
if ($scriptSource === false || preg_match('/if\s*\(\s*document\.body\.clientWidth\s*>\s*860\s*\)\s*\{\s*aplayerF\(\);/', $scriptSource)) {
    $errors[] = '播放器仍限制为桌面宽度初始化。';
}
if ($scriptSource === false || strpos($scriptSource, 'insertAfter($(".form-submit #submit"))') !== false) {
    $errors[] = '评论图片上传控件仍使用非幂等的重复插入逻辑。';
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

    if (!function_exists('sakura_meting_legacy_request_present')) {
        $errors[] = '旧播放器请求识别函数不存在。';
    } else {
        $legacyRequest = new WP_REST_Request('GET', '/sakura/v1/meting/aplayer');
        $legacyRequest->set_param('meting_nonce', 'legacy-placeholder');
        if (!sakura_meting_legacy_request_present($legacyRequest)) {
            $errors[] = '带 meting_nonce 的旧播放器请求未被识别。';
        }

        $publicTokenRequest = new WP_REST_Request('GET', '/sakura/v1/meting/aplayer');
        $publicTokenRequest->set_param('music_token', 'public-placeholder');
        if (sakura_meting_legacy_request_present($publicTokenRequest)) {
            $errors[] = '不带旧 nonce 的公开播放器请求被错误识别为旧请求。';
        }
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
    if (sakura_meting_public_cache_seconds('url') !== 15) {
        $errors[] = '音频 URL 公共缓存 TTL 被意外修改。';
    }
}

if (!class_exists('Sakura\\API\\Meting')) {
    require_once get_template_directory() . '/inc/classes/Meting.php';
}
if (class_exists('Sakura\\API\\Meting')) {
    $meting = new \Sakura\API\Meting('netease');
    $neteaseUrl = new ReflectionMethod($meting, 'netease_url');
    $neteaseUrl->setAccessible(true);
    $fallbackResult = json_decode($neteaseUrl->invoke($meting, wp_json_encode(array(
        'data' => array(array(
            'url' => 'https://example.com/original.mp3',
            'uf' => array('url' => ''),
            'size' => 1024,
            'br' => 128000,
        )),
    ))), true);
    if (!is_array($fallbackResult) || $fallbackResult['url'] !== 'https://example.com/original.mp3') {
        $errors[] = '网易云 uf.url 为空时未回退到原始音频 URL。';
    }

    $replacementResult = json_decode($neteaseUrl->invoke($meting, wp_json_encode(array(
        'data' => array(array(
            'url' => 'https://example.com/original.mp3',
            'uf' => array('url' => 'https://example.com/replacement.mp3'),
            'size' => 1024,
            'br' => 128000,
        )),
    ))), true);
    if (!is_array($replacementResult) || $replacementResult['url'] !== 'https://example.com/replacement.mp3') {
        $errors[] = '网易云合法的 uf.url 未被优先使用。';
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

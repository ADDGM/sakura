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
    array('tpl/content-thumb.php', 'substr(the_excerpt()', '文章摘要仍把 the_excerpt() 返回值传给 substr()。'),
);
foreach ($sourceChecks as $check) {
    $source = file_get_contents(get_template_directory() . '/' . $check[0]);
    if ($source === false) {
        $invalid = true;
    } elseif ($check[1] === 'utf8_encode(' || $check[1] === 'substr(the_excerpt()') {
        $invalid = strpos($source, $check[1]) !== false;
    } else {
        $invalid = strpos($source, $check[1]) === false;
    }
    if ($invalid) {
        $errors[] = $check[2];
    }
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

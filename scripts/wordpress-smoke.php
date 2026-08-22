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
);

if (strtolower($theme->get('Name')) !== 'sakura') {
    $errors[] = '当前激活主题不是 Sakura。';
}
if (!current_theme_supports('title-tag')) {
    $errors[] = '主题未启用 title-tag 支持。';
}
foreach (array('akina_setup', 'sakura_scripts', 'DEFAULT_FEATURE_IMAGE') as $function) {
    if (!function_exists($function)) {
        $errors[] = "缺少主题函数：{$function}";
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

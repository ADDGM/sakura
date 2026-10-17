<?php
/** 独立运行真实分类图片模块；WordPress 选项和钩子接口使用内存夹具。 */
if (PHP_SAPI !== 'cli') {
    echo "请在 PHP CLI 中运行本测试。\n";
    exit(1);
}

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$options = array();
$hooks = array();
function get_random_bg_url() {
    return 'https://example.com/placeholder.jpg';
}
function get_taxonomies() {
    return array('category' => 'category', 'post_tag' => 'post_tag', 'genre' => 'genre');
}
function get_option($name, $default = false) {
    global $options;
    return array_key_exists($name, $options) ? $options[$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    throw new RuntimeException('初始化不应写回选项：' . $name);
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
    add_filter($hook, $callback, $priority, $accepted_args);
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
    global $hooks;
    $hooks[$hook] = array($callback, $priority, $accepted_args);
}

require dirname(__DIR__, 2) . '/inc/categories-images.php';
if (($hooks['admin_init'][0] ?? null) !== 'z_init') {
    fwrite(STDERR, "分类图片未注册到 admin_init。\n");
    exit(1);
}
$initialize = $hooks['admin_init'][0];
$all = array('category', 'post_tag', 'genre');
$cases = array(
    'missing option' => array(array(), $all),
    'stored false' => array(array('zci_options' => false), $all),
    'stored null' => array(array('zci_options' => null), $all),
    'stored string' => array(array('zci_options' => 'legacy'), $all),
    'stored object' => array(array('zci_options' => (object) array()), $all),
    'empty options' => array(array('zci_options' => array()), $all),
    'unrelated option' => array(array('zci_options' => array('other' => 'keep')), $all),
    'false exclusions' => array(array('zci_options' => array('excluded_taxonomies' => false)), $all),
    'null exclusions' => array(array('zci_options' => array('excluded_taxonomies' => null)), $all),
    'string exclusions' => array(array('zci_options' => array('excluded_taxonomies' => 'post_tag')), $all),
    'object exclusions' => array(array('zci_options' => array('excluded_taxonomies' => (object) array())), $all),
    'empty exclusions' => array(array('zci_options' => array('excluded_taxonomies' => array())), $all),
    'exclude built-in and custom' => array(array('zci_options' => array('excluded_taxonomies' => array('post_tag', 'genre'), 'other' => 'keep')), array('category')),
    'exclude all' => array(array('zci_options' => array('excluded_taxonomies' => $all)), array()),
    'unknown exclusion' => array(array('zci_options' => array('excluded_taxonomies' => array('absent'))), $all),
);
$failures = array();
foreach ($cases as $name => [$options, $included]) {
    $hooks = array();
    $before = serialize($options);
    try {
        $initialize();
        $expected = array();
        foreach ($included as $taxonomy) {
            $expected[$taxonomy . '_add_form_fields'] = array('z_add_texonomy_field', 10, 1);
            $expected[$taxonomy . '_edit_form_fields'] = array('z_edit_texonomy_field', 10, 1);
            $expected['manage_edit-' . $taxonomy . '_columns'] = array('z_taxonomy_columns', 10, 1);
            $expected['manage_' . $taxonomy . '_custom_column'] = array('z_taxonomy_column', 10, 3);
        }
        if ($hooks !== $expected) {
            throw new RuntimeException('分类图片字段/列表钩子与排除列表不符');
        }
        if (serialize($options) !== $before) {
            throw new RuntimeException('初始化改写了原始配置');
        }
    } catch (Throwable $error) {
        $failures[$name] = $error->getMessage();
    }
}
restore_error_handler();
echo json_encode(array('php' => PHP_VERSION, 'cases' => count($cases),
    'passed' => count($cases) - count($failures), 'failures' => $failures),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
exit($failures ? 1 : 0);

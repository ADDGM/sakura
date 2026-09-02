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
    array('header.php', 'nav-default-open', '导航未使用状态类表达宽屏默认展开。'),
    array('header.php', 'id="show-nav"', '桌面导航缺少折叠按钮。'),
    array('style.css', '@media (min-width: 861px) and (max-width: 1200px)', '导航缺少中等宽度专用断点。'),
    array('style.css', '.site-top .lower nav > ul.menu > li', '中等宽度导航未兼容自定义菜单结构。'),
    array('style.css', '.site-top .lower nav > .menu > ul > li', '中等宽度导航未兼容页面回退菜单结构。'),
    array('style.css', 'flex-wrap: nowrap;', '中等宽度导航未保持顶级菜单单行排列。'),
    array('style.css', 'overflow: visible;', '中等宽度导航会裁切二级菜单。'),
    array('style.css', '#mo-nav.open', '移动端左侧抽屉导航规则缺失。'),
    array('style.css', '.submit-comment-tips:focus-within', '评论提交按钮缺少键盘焦点提示样式。'),
    array('inc/css/dash-scheme.css', 'var(--sakura-dash-', '后台配色资源未使用 CSS 变量。'),
    array('functions.php', "add_action('admin_init', 'sakura_register_dash_schemes')", '后台配色未在 admin_init 注册。'),
    array('functions.php', 'sanitize_hex_color', '后台配色未校验颜色取值。'),
    array('functions.php', "wp_add_inline_style('colors'", '后台配色未通过内联样式注入变量。'),
    array('functions.php', 'sakura_dash_scheme_custom_preset', 'Custom 默认色没有集中定义。'),
    array('functions.php', 'sakura_dash_scheme_css', '已保存配色与实时预览没有共用 CSS 生成函数。'),
    array('functions.php', 'sakura_dash_relative_luminance', '后台配色缺少 WCAG 亮度计算。'),
    array('functions.php', 'sakura_dash_contrast_ratio', '后台配色缺少 WCAG 对比度计算。'),
    array('functions.php', 'sakura_dash_readable_foreground', '后台配色缺少可读性前景回退。'),
    array('functions.php', 'sakura_dash_scheme_prepare_custom_css', 'Custom 附加 CSS 没有旧默认精确降级处理。'),
    array('functions.php', 'sakura_dash_scheme_preview_script', '个人资料页没有注册后台配色实时预览脚本。'),
    array('functions.php', "wp_enqueue_style(\n        'sakura-admin-color-scheme-preview'", '个人资料页预览没有加载静态后台配色样式。'),
    array('functions.php', "'styleSheetId' => 'sakura-admin-color-scheme-preview-css'", '个人资料页预览未标识静态后台配色样式表。'),
    array('functions.php', 'sakura_dash_scheme_localize_urls', '后台配色未把已内置资源的外链改写为本地地址。'),
    array('functions.php', "check_ajax_referer('sakura-dismiss-scheme-tip')", '配色提示关闭动作缺少 nonce 校验。'),
    array('inc/css/optionsframework.css', '#optionsframework-wrap .nav-tab', '主题设置页标签样式未收紧作用域。'),
    array('inc/css/optionsframework.css', '#optionsframework input[type="button"]', '主题设置页按钮样式未收紧作用域。'),
    array('inc/css/optionsframework.css', ':focus-visible', '主题设置页缺少键盘焦点反馈。'),
    array('inc/options-sanitize.php', 'sanitize_hex_color', 'Options Framework 颜色清理器未复用 WordPress 核心校验。'),
    array('js/admin-color-scheme-preview.js', 'styleSheetId', '个人资料页预览脚本未控制静态后台配色样式表。'),
    array('js/admin-color-scheme-preview.js', '#color-picker .color-option', '个人资料页预览脚本未覆盖 WordPress 配色卡片点击入口。'),
    array('js/admin-color-scheme-preview.js', 'input[name="admin_color"]', '个人资料页预览脚本未监听后台配色选择器。'),
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

// 旧的动态配色端点必须彻底移除：它无鉴权且直接回显查询参数，构成反射型 CSS 注入。
if (file_exists(get_template_directory() . '/inc/dash-scheme.php')) {
    $errors[] = '旧后台配色端点 inc/dash-scheme.php 仍存在。';
}

// 以下片段一旦重新出现即视为回归。
$absentChecks = array(
    array('functions.php', 'dash-scheme.php?', '后台配色仍通过查询字符串传入动态样式端点。'),
    array('functions.php', 'urlencode($rules)', '后台配色仍把自定义 CSS 拼入资源 URL。'),
    array('inc/css/optionsframework.css', "\nbody {", '主题设置页仍覆盖全局 body 样式。'),
    array('inc/css/optionsframework.css', "\ninput[type=", '主题设置页仍无作用域地覆盖表单控件。'),
    array('inc/css/optionsframework.css', "\n.nav-tab", '主题设置页仍无作用域地覆盖标签页样式。'),
    array('options.php', 'windows10-2019-4-21-i3.jpg', '后台配色默认值仍引用已失效的外部背景图。'),
    array('options.php', 'Other custom panel styles(CSS)', 'Custom 附加 CSS 设置仍使用旧名称。'),
    array('functions.php', 'window.onload', '后台通知脚本仍覆盖全局 window.onload。'),
    array('js/admin-color-scheme-preview.js', 'innerHTML', '后台配色预览脚本仍使用不安全的 innerHTML。'),
);
foreach ($absentChecks as $check) {
    $source = file_get_contents(get_template_directory() . '/' . $check[0]);
    if ($source === false || strpos($source, $check[1]) !== false) {
        $errors[] = $check[2];
    }
}

$styleSource = file_get_contents(get_template_directory() . '/style.css');
$commentsSource = file_get_contents(get_template_directory() . '/comments.php');
$headerSource = file_get_contents(get_template_directory() . '/header.php');
$decorateSource = file_get_contents(get_template_directory() . '/inc/decorate.php');
if ($headerSource === false || strpos($headerSource, "if(!akina_option('shownav'))") !== false) {
    $errors[] = '桌面导航折叠按钮仍受宽屏默认展开设置控制。';
}
if ($decorateSource === false || strpos($decorateSource, '.site-top .lower nav {display: block !important;}') !== false) {
    $errors[] = '宽屏默认展开设置仍通过内联 !important 破坏响应式导航。';
}
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
if ($optionsStyleSource === false || !preg_match('/#optionsframework input\.of-radio:checked::before\s*\{[^}]*float:\s*none;[^}]*position:\s*absolute;[^}]*transform:\s*translate\(-50%,\s*-50%\)/s', $optionsStyleSource)) {
    $errors[] = '主题设置页单选控件未清除核心浮动或未实现选中点居中。';
}
if ($optionsStyleSource === false || preg_match('/#optionsframework input\.checkbox\s*\{[^}]*border-radius:\s*50%/s', $optionsStyleSource)) {
    $errors[] = '主题设置页 checkbox 仍被绘制为圆形。';
}
if ($optionsStyleSource === false || !preg_match('/#optionsframework-submit\s*\{[^}]*display:\s*flex;[^}]*background:\s*transparent;/s', $optionsStyleSource)) {
    $errors[] = '主题设置页提交区仍未使用稳定的弹性布局和透明背景。';
}
if ($optionsStyleSource === false || preg_match('/#optionsframework \.button-primary\s*\{[^}]*background:\s*#b32d2e/s') || preg_match('/#optionsframework \.reset-button\s*\{[^}]*background:\s*#374D6F/s')) {
    $errors[] = '主题设置页保存/重置按钮仍使用旧版硬编码红蓝配色。';
}
if ($optionsStyleSource === false || strpos($optionsStyleSource, '--sakura-dash-button-bg') === false || strpos($optionsStyleSource, '#optionsframework .button-primary:hover') === false || strpos($optionsStyleSource, '#optionsframework .reset-button:hover') === false) {
    $errors[] = '主题设置页保存/重置按钮缺少后台配色令牌或状态规则。';
}
$dashSchemeSource = file_get_contents(get_template_directory() . '/inc/css/dash-scheme.css');
if ($dashSchemeSource === false || !preg_match('/\.wp-core-ui \.button-primary:active,[\s\S]*?\.wp-core-ui \.button-primary\.active:focus\s*\{[^}]*background:\s*var\(--sakura-dash-primary\);/s', $dashSchemeSource)) {
    $errors[] = '后台配色主按钮按下态未使用主色背景。';
}
if ($dashSchemeSource === false || strpos($dashSchemeSource, '--sakura-dash-button-border: #4e776d') === false || strpos($dashSchemeSource, '--sakura-dash-focus-ring: #4e776d') === false) {
    $errors[] = '后台配色静态回退未使用 Sakura 协调的按钮边界色。';
}
$previewSource = file_get_contents(get_template_directory() . '/js/admin-color-scheme-preview.js');
if ($previewSource === false || !preg_match('/\.textContent\s*=/', $previewSource) || !preg_match('/\.disabled\s*=/', $previewSource) || !preg_match('/\.addEventListener\(\s*[\'\"]change[\'\"]/', $previewSource) || !preg_match('/\.addEventListener\(\s*[\'\"]click[\'\"]/', $previewSource)) {
    $errors[] = '个人资料页后台配色预览脚本缺少安全写入、样式表切换或配色卡事件监听。';
}

if (function_exists('optionsframework_options') && function_exists('sakura_dash_scheme_custom_preset')) {
    $customPreset = sakura_dash_scheme_custom_preset();
    $optionDefinitions = optionsframework_options();
    $defaultsById = array();
    foreach ($optionDefinitions as $definition) {
        if (isset($definition['id'])) {
            $defaultsById[$definition['id']] = $definition['std'] ?? null;
        }
    }
    $defaultMap = array(
        'dash_scheme_color_a' => 'base',
        'dash_scheme_color_b' => 'primary',
        'dash_scheme_color_c' => 'highlight',
        'dash_scheme_color_d' => 'notification',
        'dash_scheme_color_base' => 'icon_base',
        'dash_scheme_color_focus' => 'icon_focus',
        'dash_scheme_color_current' => 'icon_current',
    );
    foreach ($defaultMap as $optionKey => $presetKey) {
        if (($defaultsById[$optionKey] ?? null) !== $customPreset[$presetKey]) {
            $errors[] = "Custom 默认值 {$optionKey} 未与集中预设一致。";
        }
    }
}

if (function_exists('of_normalize_hex')) {
    foreach (array('#abc' => '#abc', 'abc' => '#abc', '%23abc' => '#abc', '#AABBCC' => '#AABBCC') as $input => $expected) {
        if (of_normalize_hex($input) !== $expected) {
            $errors[] = "颜色清理器未正确兼容 {$input}。";
        }
    }
    if (of_sanitize_hex('invalid', '#123456') !== '#123456') {
        $errors[] = '非法颜色没有回退到指定默认值。';
    }
}

if (function_exists('sakura_dash_contrast_ratio') && function_exists('sakura_dash_readable_foreground')) {
    $blackWhiteRatio = sakura_dash_contrast_ratio('#000000', '#ffffff');
    if (null === $blackWhiteRatio || $blackWhiteRatio < 20.99) {
        $errors[] = 'WCAG 黑白对比度计算错误。';
    }
    $readable = sakura_dash_readable_foreground('#ffffff', '#fedcd2', 4.5);
    $readableRatio = sakura_dash_contrast_ratio($readable, '#fedcd2');
    if (null === $readableRatio || $readableRatio < 4.5) {
        $errors[] = '浅色背景的文字前景回退未达到 4.5:1。';
    }
    $customReadable = sakura_dash_readable_foreground('#ffffff', '#c6742b', 4.5);
    if (sakura_dash_contrast_ratio($customReadable, '#c6742b') < 4.5) {
        $errors[] = 'Custom 默认主色的文字前景回退未达到 4.5:1。';
    }
    if (function_exists('sakura_dash_scheme_variables')) {
        $sakuraVars = sakura_dash_scheme_variables('sakura');
        $sakuraEdge = $sakuraVars['--sakura-dash-button-border'] ?? '';
        $sakuraFocus = $sakuraVars['--sakura-dash-focus-ring'] ?? '';
        if ($sakuraEdge !== '#4e776d' || $sakuraFocus !== '#4e776d') {
            $errors[] = 'Sakura 按钮边框和焦点环未使用协调的主题边界色。';
        }
        if (sakura_dash_contrast_ratio($sakuraEdge, '#bfd8d2') < 3 || sakura_dash_contrast_ratio($sakuraFocus, '#f1f1f1') < 3) {
            $errors[] = 'Sakura 按钮边框或焦点环对比度未达到 3:1。';
        }
    }
}

if (function_exists('sakura_dash_scheme_legacy_custom_css') && function_exists('sakura_dash_scheme_prepare_custom_css')) {
    $legacyCss = sakura_dash_scheme_legacy_custom_css();
    foreach (array($legacyCss, "\r\n" . str_replace("\n", "\r\n", $legacyCss) . " \t") as $legacyVariant) {
        if (sakura_dash_scheme_prepare_custom_css($legacyVariant) !== '') {
            $errors[] = '旧版 Custom 默认 CSS 没有按精确匹配降级为空。';
            break;
        }
    }
    if (sakura_dash_scheme_prepare_custom_css($legacyCss . '/* changed */') === '') {
        $errors[] = '修改过的 Custom CSS 被错误当作旧默认值清空。';
    }
}

if (function_exists('sakura_dash_scheme_css')) {
    if (sakura_dash_scheme_css('fresh') !== '') {
        $errors[] = '核心后台配色仍残留 Sakura/Custom 内联变量。';
    }
    $frameworkFilter = static function ($value) {
        return array('id' => 'sakura');
    };
    $optionFilter = static function ($value) {
        return array(
            'dash_scheme_color_a' => '#abc',
            'dash_scheme_color_b' => 'invalid',
            'dash_scheme_color_c' => '',
            'dash_scheme_color_d' => '#aabbcc',
            'dash_scheme_color_base' => '#fff',
            'dash_scheme_color_focus' => null,
            'dash_scheme_color_current' => '#123456',
            'dash_scheme_css_rules' => '.smoke-test{color:red;}',
        );
    };
    add_filter('pre_option_optionsframework', $frameworkFilter);
    add_filter('pre_option_sakura', $optionFilter);
    $smokeColors = sakura_dash_scheme_custom_colors();
    $customCss = sakura_dash_scheme_css('custom');
    remove_filter('pre_option_optionsframework', $frameworkFilter);
    remove_filter('pre_option_sakura', $optionFilter);
    if ($smokeColors['base'] !== '#abc' || $smokeColors['primary'] !== '#d88e4c' || $smokeColors['highlight'] !== '#695644' || $smokeColors['icon_focus'] !== '#ffffff') {
        $errors[] = 'Custom 非法或空颜色没有按集中预设安全回退。';
    }
    if (strpos($customCss, '.smoke-test{color:red;}') === false || strpos($customCss, '--sakura-dash-primary:') === false) {
        $errors[] = 'Custom CSS 生成没有同时包含变量和附加规则。';
    }
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

if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    $debugLog = WP_CONTENT_DIR . '/debug.log';
    if (file_exists($debugLog)) {
        $debugContents = file_get_contents($debugLog);
        if (is_string($debugContents) && preg_match('/(?:Deprecated|Warning|Notice|Fatal error).*themes[\\\\\/]sakura|themes[\\\\\/].*sakura.*(?:Deprecated|Warning|Notice|Fatal error)/i', $debugContents)) {
            $errors[] = 'WP_DEBUG 日志包含 Sakura 主题的 Deprecated、Warning、Notice 或 Fatal。';
        }
    }
}

if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}

echo "WordPress 主题烟雾检查通过：{$theme->get('Version')}\n";

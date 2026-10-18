<?php
/** 由 wordpress-smoke.php 在真实 WordPress 环境调用。 */
$catalogOption = 'sakura_catalog_smoke';
$catalogSaved = get_option($catalogOption, null);
$catalogScripts = $GLOBALS['wp_scripts'] ?? null;
$catalogErrors = $GLOBALS['wp_settings_errors'] ?? array();
$catalogFramework = static function () use ($catalogOption) { return array('id' => $catalogOption); };
$catalogCheck = static function ($condition, $message) use (&$errors) {
    if (!$condition) $errors[] = '外置服装清单：' . $message;
};
add_filter('pre_option_optionsframework', $catalogFramework);
set_error_handler(static function ($severity, $message) use (&$errors) {
    if (error_reporting() & $severity) $errors[] = '外置服装清单 PHP 诊断：' . $message;
    return true;
});
try {
    $input = of_get_default_values();
    // A submitted form includes empty upload/text fields that have no default.
    foreach (_optionsframework_options() as $field) {
        if (isset($field['id'], $field['type']) && !array_key_exists($field['id'], $input)) $input[$field['id']] = '';
    }
    $valid = 'https://example.test/uploads/pio/pack-v1/catalog.json?v=1&lang=zh';
    $input['live2d_catalog'] = $valid;
    update_option($catalogOption, optionsframework_validate($input));
    $catalogCheck(akina_option('live2d_catalog') === $valid, '有效地址未沿完整设置链保存/读取');
    foreach (array('javascript:alert(1)', '/catalog.json', '//example.test/catalog.json',
        'https://user:pass@example.test/catalog.json', 'https://example.test/catalog.json#x',
        'https://example.test/catalog.png', "https://example.test/\nfile.json", array('bad')) as $invalid) {
        $input['live2d_catalog'] = $invalid;
        $GLOBALS['wp_settings_errors'] = array();
        update_option($catalogOption, optionsframework_validate($input));
        $catalogCheck(akina_option('live2d_catalog') === $valid, '非法输入未保留已保存地址');
        $found = array_filter(get_settings_errors('options-framework'), static function ($error) {
            return $error['code'] === 'live2d_catalog' && $error['type'] === 'error';
        });
        $catalogCheck(count($found) === 1, '非法输入没有可理解的后台错误');
    }
    foreach (array(
        array(false, false, '', false, false),
        array(true, false, '', false, false),
        array(true, false, $valid, true, false),
        array(true, true, $valid, false, true),
    ) as $case) {
        list($enabled, $remote, $address, $hasCatalog, $hasRemote) = $case;
        $legacyInput = $input;
        unset($legacyInput['live2d_costume_source']);
        update_option($catalogOption, array_merge($legacyInput, array('live2d_s' => $enabled, 'live2d_m' => 'pio',
            'live2d_b' => $remote, 'live2d_i' => 'https://example.test/random/', 'live2d_catalog' => $address)));
        $GLOBALS['wp_scripts'] = new WP_Scripts();
        sakura_scripts();
        $scripts = wp_scripts();
        $catalogCheck(wp_script_is('sakura-live2d-catalog', 'enqueued') === $hasCatalog, '默认/关闭/远程模式的清单入队错误');
        if ($enabled) {
            $source = $scripts->get_data('sakura-live2d-message', 'data');
            preg_match('/var SakuraLive2D = (.*);/', $source, $matches);
            $config = json_decode($matches[1] ?? '', true);
            $catalogCheck(is_array($config) && $config['catalogUrl'] === ($hasCatalog ? $valid : ''), '前端配置地址不正确');
            $catalogCheck(strpos($scripts->registered['sakura-live2d-loader']->src, $hasRemote ? 'run_field.js' : 'run_local.js') !== false, '旧远程PNG加载器被改变');
            if ($hasCatalog) {
                $catalogCheck(in_array('sakura-live2d-catalog', $scripts->registered['sakura-live2d-loader']->deps, true), '清单未成为loader依赖');
                $catalogCheck($config['catalogModel'] === array('id' => 'pio', 'sha256' => hash_file('sha256', get_template_directory() . '/live2d/model/pio/model.moc')), '模型指纹不匹配当前文件');
            }
        }
    }
    $fixedReference = static function () { return str_repeat('a', 40); };
    add_filter('sakura_remote_resource_tag', $fixedReference);
    try {
        foreach (array('cdn', 'local', 'bundled', 'custom', 'remote') as $costumeSource) {
            $explicit = array_merge($input, array('live2d_s' => true, 'live2d_m' => 'pio',
                'live2d_costume_source' => $costumeSource, 'live2d_i' => 'https://example.test/random/', 'live2d_catalog' => $valid));
            update_option($catalogOption, optionsframework_validate($explicit));
            $catalogCheck(akina_option('live2d_costume_source') === $costumeSource, '来源选择未沿完整设置链保存');
            $invalidSource = $explicit;
            $invalidSource['live2d_costume_source'] = array('invalid');
            update_option($catalogOption, optionsframework_validate($invalidSource));
            $catalogCheck(akina_option('live2d_costume_source') === $costumeSource, '非法来源未保留原选择');
            $GLOBALS['wp_scripts'] = new WP_Scripts();
            sakura_scripts();
            preg_match('/var SakuraLive2D = (.*);/', wp_scripts()->get_data('sakura-live2d-message', 'data'), $matches);
            $config = json_decode($matches[1] ?? '', true);
            $catalogCheck(count($config['costumeUrls']) === ($costumeSource === 'cdn' ? 104 : 0), '托管来源与自定义来源未互斥');
            $catalogCheck($config['catalogUrl'] === ($costumeSource === 'custom' ? $valid : ''), '自定义清单来源未互斥');
            $catalogCheck(($config['dressUrl'] !== '') === ($costumeSource === 'remote'), '远程PNG来源未互斥');
        }
    } finally { remove_filter('sakura_remote_resource_tag', $fixedReference); }
    $input['live2d_catalog'] = '';
    update_option($catalogOption, optionsframework_validate($input));
    $catalogCheck(akina_option('live2d_catalog') === '', '空值未关闭清单');
    $uploadFilter = static function ($uploads) {
        $uploads['basedir'] = WP_CONTENT_DIR . '/custom-uploads/sites/42';
        $uploads['baseurl'] = 'https://static.example.test/sites/42';
        return $uploads;
    };
    add_filter('upload_dir', $uploadFilter);
    try {
        $fields = optionsframework_options();
        $field = array_values(array_filter($fields, static function ($entry) {return ($entry['id'] ?? '') === 'live2d_catalog';}))[0];
        $catalogCheck(strpos($field['desc'], WP_CONTENT_DIR) === false &&
            strpos($field['desc'], '/custom-uploads/sites/42') === false &&
            strpos($field['desc'], 'static.example.test') === false,
            '后台说明泄露服务器磁盘路径或本站上传地址');
        $catalogCheck(strpos($field['desc'], '<code>wp-content/uploads/sakura-live2d/</code>') !== false &&
            strpos($field['desc'], '<code>https://example.com/wp-content/uploads/sakura-live2d/pio/pack-v1/catalog.json</code>') !== false,
            '后台通用目录或占位网址示例不正确');
        $catalogCheck(!is_dir(WP_CONTENT_DIR . '/custom-uploads/sites/42'), '显示设置创建了上传目录');
    } finally { remove_filter('upload_dir', $uploadFilter); }
    require_once ABSPATH . WPINC . '/pomo/mo.php';
    foreach (array('zh_CN', 'zh_TW', 'en_US', 'ja') as $locale) {
        $mo = new MO();
        $catalogCheck($mo->import_from_file(get_template_directory() . '/languages/' . $locale . '.mo') &&
            isset($mo->entries['External Live2D costume catalog']) &&
            isset($mo->entries['Enter a full HTTP(S) URL to a JSON catalog, without credentials or a fragment. The previous value has been kept.']), 'MO翻译缺失：' . $locale);
    }
} finally {
    restore_error_handler();
    remove_filter('pre_option_optionsframework', $catalogFramework);
    if ($catalogSaved === null) delete_option($catalogOption); else update_option($catalogOption, $catalogSaved);
    $GLOBALS['wp_scripts'] = $catalogScripts;
    $GLOBALS['wp_settings_errors'] = $catalogErrors;
}

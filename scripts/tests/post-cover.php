<?php
/** 由 wordpress-smoke.php 在真实 WordPress 环境调用。 */
$coverUser = get_current_user_id();
$coverPostData = $_POST;
$coverGetData = $_GET;
$coverRedirect = isset($GLOBALS['wp_filter']['redirect_post_location']) ? clone $GLOBALS['wp_filter']['redirect_post_location'] : null;
$coverIds = array();
$coverCheck = function ($condition, $message) use (&$errors) {
    if (!$condition) $errors[] = '文章封面：' . $message;
};
try {
    wp_set_current_user(get_user_by('login', 'admin')->ID);
    $coverPayload = function ($id, $overrides = array()) {
        $values = array();
        $hashes = array();
        foreach (sakura_post_cover_fields() as $field => $key) {
            $values[$field] = (string) get_post_meta($id, $key, true);
            $hashes[$field] = wp_hash($values[$field]);
        }
        return wp_slash(array('sakura_cover_nonce' => wp_create_nonce('sakura_post_cover_' . $id),
            'sakura_cover' => array_merge($values, $overrides), 'sakura_cover_original' => $hashes));
    };
    foreach (array('post', 'page') as $postType) {
        $_POST = array();
        $id = wp_insert_post(array('post_title' => 'Sakura cover smoke', 'post_type' => $postType, 'post_status' => 'draft'));
        $coverIds[] = $id;
        $video = "https://example.com/video.m3u8?a=1&b=2,3&name=O'Reilly";
        $_POST = $coverPayload($id, array('type' => 'hls', 'video' => $video, 'poster' => 'https://example.com/poster.jpg'));
        wp_update_post(array('ID' => $id, 'post_title' => 'Sakura saved cover'));
        $coverCheck(get_post_meta($id, 'cover_type', true) === 'hls' && get_post_meta($id, 'video_cover', true) === $video, '保存钩子未保留类型/带参数地址');

        $_POST = $coverPayload($id, array('type' => 'normal', 'video' => 'https://example.com/flower.webm'));
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'cover_type', true) === 'normal', '普通视频未保存');
        $_POST = $coverPayload($id, array('type' => ''));
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'cover_type', true) === '' && get_post_meta($id, 'video_cover', true) === 'https://example.com/flower.webm', '图片模式丢失视频配置');

        $_POST = $coverPayload($id);
        update_post_meta($id, 'video_cover', 'https://example.com/custom.mp4');
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'video_cover', true) === 'https://example.com/custom.mp4', '未操作的面板覆盖旧字段更新');

        foreach (array('javascript:alert(1)', '//example.com/video', 'https://user:pass@example.com/video', "https://example.com/\nvideo", array('bad')) as $invalid) {
            $_POST = $coverPayload($id, array('type' => 'hls', 'video' => $invalid));
            wp_update_post(array('ID' => $id));
            $coverCheck(get_post_meta($id, 'cover_type', true) === '' && get_post_meta($id, 'video_cover', true) === 'https://example.com/custom.mp4', '非法输入部分覆盖原值');
        }
        $_POST = $coverPayload($id, array('type' => 'hls', 'video' => ''));
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'cover_type', true) === '', '空视频地址启用视频');
        $_POST = $coverPayload($id, array('type' => 'invalid'));
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'cover_type', true) === '', '接受未知类型');

        $_POST = $coverPayload($id, array('poster' => 'https://example.com/new.jpg'));
        $_POST['sakura_cover_nonce'] = 'bad';
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'video_cover_thumb', true) === 'https://example.com/poster.jpg', '无效nonce保存成功');
        $_POST = $coverPayload($id, array('poster' => 'https://example.com/new.jpg'));
        unset($_POST['sakura_cover']['video']);
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'video_cover_thumb', true) === 'https://example.com/poster.jpg', '缺字段请求破坏数据');
        $_POST = array();
        wp_update_post(array('ID' => $id));
        $coverCheck(get_post_meta($id, 'video_cover', true) === 'https://example.com/custom.mp4', '无面板请求清空字段');

        wp_set_current_user(0);
        $_POST = $coverPayload($id, array('poster' => 'https://example.com/new.jpg'));
        sakura_post_cover_save($id);
        $coverCheck(get_post_meta($id, 'video_cover_thumb', true) === 'https://example.com/poster.jpg', '无权限请求保存成功');
        wp_set_current_user(get_user_by('login', 'admin')->ID);
        $_POST = array();
        $revision = wp_insert_post(array('post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id, 'post_title' => 'cover revision'));
        $coverIds[] = $revision;
        $_POST = $coverPayload($revision, array('type' => 'hls', 'video' => $video));
        sakura_post_cover_save($revision);
        $coverCheck(!metadata_exists('post', $revision, 'video_cover'), '修订被写入封面配置');

        $_POST = $coverPayload($id, array('poster' => 'https://example.com/failure.jpg'));
        $fail = function ($check, $objectId, $key) use ($id) { return $objectId === $id && $key === 'video_cover_thumb' ? false : $check; };
        add_filter('update_post_metadata', $fail, 10, 3);
        try { sakura_post_cover_save($id); } finally { remove_filter('update_post_metadata', $fail, 10); }
        $coverCheck(strpos(apply_filters('redirect_post_location', 'http://example.com/edit'), 'sakura-cover-error=save') !== false, '未报告写入失败');

        update_post_meta($id, 'video_cover_thumb', 'https://example.com/" onerror="alert(1)');
        ob_start();
        sakura_post_cover_render(get_post($id));
        $html = ob_get_clean();
        $coverCheck(strpos($html, ' onerror="') === false && strpos($html, 'sakura_cover_original[video]') !== false, '面板转义或原值指纹缺失');
        foreach (array('invalid', 'save', array('invalid')) as $errorCode) {
            $_GET['sakura-cover-error'] = $errorCode;
            ob_start();
            sakura_post_cover_render(get_post($id));
            $html = ob_get_clean();
            $expected = is_string($errorCode) ? $errorCode : '';
            $coverCheck(strpos($html, 'data-save-error="' . $expected . '"') !== false, '异步保存错误状态未限制为已知代码');
        }
        $_GET = $coverGetData;
    }
    require_once ABSPATH . 'wp-admin/includes/template.php';
    sakura_post_cover_meta_box();
    foreach (array('post', 'page') as $postType) {
        $box = $GLOBALS['wp_meta_boxes'][$postType]['normal']['high']['sakura-post-cover'] ?? array();
        $coverCheck(!empty($box['args']['__block_editor_compatible_meta_box']), '未注册区块编辑器兼容面板');
    }
} finally {
    $_POST = array();
    foreach (array_reverse($coverIds) as $id) wp_delete_post($id, true);
    $_POST = $coverPostData;
    $_GET = $coverGetData;
    wp_set_current_user($coverUser);
    if ($coverRedirect) $GLOBALS['wp_filter']['redirect_post_location'] = $coverRedirect;
    else unset($GLOBALS['wp_filter']['redirect_post_location']);
}

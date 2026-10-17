<?php
/**
 * 文章封面编辑入口；保留现有自定义字段与前台播放契约。
 */
if (!defined('ABSPATH')) {
    exit;
}

function sakura_post_cover_fields()
{
    return array('type' => 'cover_type', 'video' => 'video_cover', 'poster' => 'video_cover_thumb');
}

function sakura_post_cover_meta_box()
{
    add_meta_box('sakura-post-cover', __('Post cover', 'sakura'), 'sakura_post_cover_render',
        array('post', 'page'), 'normal', 'high', array('__block_editor_compatible_meta_box' => true));
}
add_action('add_meta_boxes', 'sakura_post_cover_meta_box');

function sakura_post_cover_assets($hook)
{
    $screen = get_current_screen();
    if (!in_array($hook, array('post.php', 'post-new.php'), true) || !$screen
        || !in_array($screen->post_type, array('post', 'page'), true)) {
        return;
    }
    wp_enqueue_media();
    wp_enqueue_script('sakura-post-cover', get_template_directory_uri() . '/inc/js/post-cover.js',
        array('jquery', 'media-views', 'wp-data', 'wp-api-fetch'), SAKURA_VERSION, true);
    wp_enqueue_style('sakura-post-cover', get_template_directory_uri() . '/inc/css/post-cover.css', array(), SAKURA_VERSION);
}
add_action('admin_enqueue_scripts', 'sakura_post_cover_assets');

function sakura_post_cover_render($post)
{
    $values = array();
    foreach (sakura_post_cover_fields() as $field => $key) {
        $value = get_post_meta($post->ID, $key, true);
        $values[$field] = is_string($value) ? $value : '';
    }
    if (!in_array($values['type'], array('normal', 'hls'), true)) {
        $values['type'] = '';
    }
    $featured = get_the_post_thumbnail_url($post, 'medium');
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $save_error = isset($_GET['sakura-cover-error']) && in_array($_GET['sakura-cover-error'], array('invalid', 'save'), true)
        ? $_GET['sakura-cover-error'] : '';
    wp_nonce_field('sakura_post_cover_' . $post->ID, 'sakura_cover_nonce');
    ?>
    <div class="sakura-cover-fields" data-block-editor="<?php echo $screen && $screen->is_block_editor() ? '1' : '0'; ?>" data-save-error="<?php echo esc_attr($save_error); ?>">
        <?php foreach ($values as $field => $value) : ?>
            <input type="hidden" name="sakura_cover_original[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr(wp_hash($value)); ?>">
        <?php endforeach; ?>
        <p><label for="sakura-cover-type"><?php esc_html_e('Cover type', 'sakura'); ?></label>
            <select id="sakura-cover-type" name="sakura_cover[type]">
                <option value="" <?php selected($values['type'], ''); ?>><?php esc_html_e('Image (featured image)', 'sakura'); ?></option>
                <option value="normal" <?php selected($values['type'], 'normal'); ?>><?php esc_html_e('Video (MP4 / WebM)', 'sakura'); ?></option>
                <option value="hls" <?php selected($values['type'], 'hls'); ?>><?php esc_html_e('HLS video (m3u8)', 'sakura'); ?></option>
            </select>
        </p>
        <p class="description"><?php esc_html_e('Choose the cover image in the Featured image panel. A featured image is required for video covers too. Save or update the post to apply these settings.', 'sakura'); ?></p>
        <?php if (akina_option('patternimg')) : ?>
            <p class="notice notice-warning inline"><?php esc_html_e('Header images are disabled in Sakura settings. Enable them to display this cover.', 'sakura'); ?></p>
        <?php endif; ?>
        <p class="sakura-cover-error notice notice-error inline" role="alert" hidden><?php esc_html_e('Cover settings were not changed. Select a valid type and complete HTTP(S) URLs; a video cover needs a video URL.', 'sakura'); ?></p>
        <p class="sakura-cover-save-error notice notice-error inline" role="alert" hidden><?php esc_html_e('Cover settings could not be saved completely. Reload the editor and try again.', 'sakura'); ?></p>
        <div class="sakura-cover-video-fields">
            <p><label for="sakura-cover-video"><?php esc_html_e('Cover video URL', 'sakura'); ?></label>
                <input class="widefat" type="text" inputmode="url" id="sakura-cover-video" name="sakura_cover[video]" value="<?php echo esc_attr($values['video']); ?>" placeholder="https://" aria-describedby="sakura-cover-video-help">
            </p>
            <p class="description" id="sakura-cover-video-help"><?php esc_html_e('Use a complete HTTP(S) media URL, not a video website page. Choose HLS for m3u8 streams. Visitors can read this URL.', 'sakura'); ?></p>
            <p><label for="sakura-cover-poster"><?php esc_html_e('Video poster URL', 'sakura'); ?></label>
                <input class="widefat" type="text" inputmode="url" id="sakura-cover-poster" name="sakura_cover[poster]" value="<?php echo esc_attr($values['poster']); ?>" placeholder="https://" aria-describedby="sakura-cover-poster-help">
            </p>
            <p>
                <button type="button" class="button sakura-cover-choose" data-title="<?php esc_attr_e('Choose video poster', 'sakura'); ?>" data-button="<?php esc_attr_e('Use this image', 'sakura'); ?>"><?php esc_html_e('Choose from media library', 'sakura'); ?></button>
                <button type="button" class="button sakura-cover-clear"><?php esc_html_e('Use featured image', 'sakura'); ?></button>
            </p>
            <p class="description" id="sakura-cover-poster-help"><?php esc_html_e('Leave empty to use the featured image. Switching to image cover keeps the video settings for later.', 'sakura'); ?></p>
            <img class="sakura-cover-preview" src="<?php echo esc_url($values['poster'] ?: $featured); ?>" data-featured="<?php echo esc_url($featured); ?>" alt="<?php esc_attr_e('Video poster preview', 'sakura'); ?>" <?php echo ($values['poster'] || $featured) ? '' : 'hidden'; ?>>
        </div>
    </div>
    <?php
}

function sakura_post_cover_error($code)
{
    add_filter('redirect_post_location', function ($location) use ($code) {
        return add_query_arg('sakura-cover-error', $code, $location);
    });
}

function sakura_post_cover_notice()
{
    $screen = get_current_screen();
    if (!$screen || $screen->base !== 'post' || !isset($_GET['sakura-cover-error'])) {
        return;
    }
    $code = $_GET['sakura-cover-error'];
    if (!in_array($code, array('invalid', 'save'), true)) {
        return;
    }
    $message = $code === 'invalid'
        ? __('Cover settings were not changed. Select a valid type and complete HTTP(S) URLs; a video cover needs a video URL.', 'sakura')
        : __('Cover settings could not be saved completely. Reload the editor and try again.', 'sakura');
    echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
}
add_action('admin_notices', 'sakura_post_cover_notice');

function sakura_post_cover_save($post_id)
{
    if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_autosave($post_id)
        || wp_is_post_revision($post_id) || !in_array(get_post_type($post_id), array('post', 'page'), true)
        || !current_user_can('edit_post', $post_id) || !isset($_POST['sakura_cover_nonce'])
        || !is_string($_POST['sakura_cover_nonce'])
        || !wp_verify_nonce(wp_unslash($_POST['sakura_cover_nonce']), 'sakura_post_cover_' . $post_id)) {
        return;
    }
    if (!isset($_POST['sakura_cover'], $_POST['sakura_cover_original'])
        || !is_array($_POST['sakura_cover']) || !is_array($_POST['sakura_cover_original'])) {
        return;
    }
    $input = wp_unslash($_POST['sakura_cover']);
    $original = wp_unslash($_POST['sakura_cover_original']);
    $changes = array();
    foreach (sakura_post_cover_fields() as $field => $key) {
        if (!isset($input[$field], $original[$field]) || !is_string($input[$field]) || !is_string($original[$field])) {
            sakura_post_cover_error('invalid');
            return;
        }
        // 未操作的面板不能覆盖自定义字段或其他编辑入口的更新。
        if (hash_equals(wp_hash($input[$field]), $original[$field])) {
            continue;
        }
        $value = trim($input[$field]);
        if ($field === 'type') {
            $valid = in_array($value, array('', 'normal', 'hls'), true);
        } else {
            $parts = wp_parse_url($value);
            $valid = $value === '' || (is_array($parts) && !empty($parts['host'])
                && isset($parts['scheme']) && in_array(strtolower($parts['scheme']), array('http', 'https'), true)
                && !isset($parts['user']) && !isset($parts['pass'])
                && !preg_match('/[\x00-\x20<>"\\\\]/', $value) && esc_url_raw($value, array('http', 'https')) !== '');
        }
        if (!$valid) {
            sakura_post_cover_error('invalid');
            return;
        }
        $changes[$key] = $value;
    }
    if (!$changes) {
        return;
    }
    $type = $changes['cover_type'] ?? get_post_meta($post_id, 'cover_type', true);
    $video = $changes['video_cover'] ?? get_post_meta($post_id, 'video_cover', true);
    if (in_array($type, array('normal', 'hls'), true) && $video === '') {
        sakura_post_cover_error('invalid');
        return;
    }
    foreach ($changes as $key => $value) {
        update_post_meta($post_id, $key, wp_slash($value));
        if (get_post_meta($post_id, $key, true) !== $value) {
            sakura_post_cover_error('save');
            return;
        }
    }
}
add_action('save_post', 'sakura_post_cover_save', 20);

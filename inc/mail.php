<?php
/**
 * Sakura mail helpers and HTML templates.
 *
 * Easy WP SMTP (or another WordPress mailer) remains responsible for SMTP
 * transport. This module only prepares messages and calls wp_mail().
 */

/**
 * Return a safe display name for the site in mail headers and templates.
 *
 * @return string
 */
function sakura_get_mail_blog_name()
{
    $value = get_option('blogname', 'Sakura');
    return is_scalar($value) && trim((string) $value) !== ''
        ? sanitize_text_field(wp_specialchars_decode((string) $value, ENT_QUOTES))
        : 'Sakura';
}

/**
 * Resolve the sender address used by Sakura notifications.
 *
 * IP addresses and localhost are deliberately rejected as mail domains. On
 * an internal site the administrator email supplies the fallback domain.
 *
 * @return string
 */
function sakura_get_mail_from_address()
{
    $prefix = function_exists('akina_option') ? akina_option('mail_user_name', 'bibi') : 'bibi';
    $prefix = is_scalar($prefix) ? trim((string) $prefix) : '';
    $prefix = preg_replace('/[^A-Za-z0-9.!#$%&\'*+\/?^_`{|}~-]/', '', $prefix);
    $prefix = trim($prefix, '.');
    if ($prefix === '') {
        return '';
    }

    $site_host = function_exists('wp_parse_url') ? wp_parse_url(home_url('/'), PHP_URL_HOST) : '';
    $site_host = is_string($site_host) ? strtolower(trim($site_host, '.')) : '';
    $site_host = preg_replace('/^www\./', '', $site_host);
    $admin_email_value = get_option('admin_email', '');
    $admin_email = is_scalar($admin_email_value) ? sanitize_email((string) $admin_email_value) : '';
    $admin_parts = explode('@', $admin_email);
    $admin_host = count($admin_parts) === 2 ? strtolower(trim($admin_parts[1], '.')) : '';

    $is_valid_host = static function ($host) {
        return is_string($host)
            && $host !== ''
            && strpos($host, '.') !== false
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host);
    };

    $host = $is_valid_host($site_host) ? $site_host : ($is_valid_host($admin_host) ? $admin_host : '');
    if ($host === '') {
        return '';
    }

    $address = sanitize_email($prefix . '@' . $host);
    return is_email($address) ? $address : '';
}

/**
 * Build headers for an HTML WordPress email.
 *
 * @param string $from Sender address.
 * @return array
 */
function sakura_get_mail_headers($from)
{
    $from = sanitize_email($from);
    if (!is_email($from)) {
        return array();
    }

    $blog_name = sakura_get_mail_blog_name();
    $charset_value = get_bloginfo('charset');
    $charset = is_scalar($charset_value) && trim((string) $charset_value) !== '' ? (string) $charset_value : 'UTF-8';
    return array(
        'From: ' . $blog_name . ' <' . $from . '>',
        'Content-Type: text/html; charset=' . sanitize_text_field($charset),
    );
}

/**
 * Send an HTML message and retain wp_mail_failed details for the caller.
 *
 * @return array{sent:bool,error:string}
 */
function sakura_send_html_mail($to, $subject, $message, $headers)
{
    $mail_error = null;
    $failure_listener = static function ($error) use (&$mail_error) {
        $mail_error = $error;
    };

    add_action('wp_mail_failed', $failure_listener);
    $sent = wp_mail($to, $subject, $message, $headers);
    remove_action('wp_mail_failed', $failure_listener);

    $error_message = '';
    if (is_wp_error($mail_error)) {
        $error_message = $mail_error->get_error_message();
    }

    return array(
        'sent' => (bool) $sent,
        'error' => is_scalar($error_message) ? trim((string) $error_message) : '',
    );
}

/**
 * Render the shared Sakura mail frame.
 *
 * @param string $title Message title.
 * @param string $content Already prepared HTML content.
 * @return string
 */
function sakura_render_mail_template($title, $content)
{
    $title = esc_html($title);
    $content = wp_kses_post($content);
    $blog_name = esc_html(sakura_get_mail_blog_name());
    $home_url = esc_url(home_url('/'));
    $year = esc_html(function_exists('wp_date') ? wp_date('Y') : date('Y'));

    return '<!doctype html><html lang="zh-CN"><head><meta charset="UTF-8"></head><body style="margin:0;padding:24px 0;background:#f3f4f6;color:#2f3333;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Microsoft YaHei,sans-serif;line-height:1.6;">'
        . '<div style="width:95%;max-width:800px;margin:0 auto;background:#fff;border:1px solid #f0a000;border-radius:5px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,.12);">'
        . '<div style="padding:18px 24px;background:#f0a000;color:#fff;font-size:18px;font-weight:600;">' . $title . '</div>'
        . '<div style="padding:24px;">'
        . '<p style="margin:0 0 18px;font-size:14px;">来自 <a style="color:#d98200;text-decoration:none;" href="' . $home_url . '" target="_blank" rel="noopener">' . $blog_name . '</a> 的系统邮件</p>'
        . $content
        . '<p style="margin:24px 0 0;padding-top:14px;border-top:1px solid #ddd;text-align:center;color:#999;font-size:12px;">本邮件为系统自动发出，请勿直接回复<br>&copy; ' . $year . ' ' . $blog_name . '</p>'
        . '</div></div></body></html>';
}

/**
 * Prepare comment content for inclusion in an HTML email.
 *
 * @param mixed $content Comment content.
 * @return string
 */
function sakura_prepare_comment_mail_content($content)
{
    $content = is_scalar($content) ? trim((string) $content) : '';
    $content = str_replace('{UPLOAD}', 'https://i.loli.net/', $content);
    $content = preg_replace_callback('/\{\{([A-Za-z0-9_-]+)\}\}/', static function ($matches) {
        $emoji = rawurlencode($matches[1]);
        return '<img src="https://cdn.jsdelivr.net/gh/moezx/cdn@2.9.4/img/bili/hd/ic_emoji_' . $emoji . '.png" alt="emoji" style="height:2em;max-height:2em;">';
    }, $content);
    $content = preg_replace_callback('/\[img\]\s*(.*?)\s*\[\/img\]/is', static function ($matches) {
        $url = esc_url(trim(wp_strip_all_tags($matches[1])));
        return $url ? '<img src="' . $url . '" alt="" style="max-width:100%;height:auto;">' : '';
    }, $content);
    if (function_exists('convert_smilies')) {
        $content = convert_smilies($content);
    }
    return wpautop(wp_kses_post($content));
}

/**
 * Render a comment reply using the shared mail frame.
 *
 * @param WP_Comment $comment Reply comment.
 * @param WP_Comment $parent Parent comment.
 * @return string
 */
function sakura_render_comment_mail($comment, $parent)
{
    $recipient_name = esc_html(trim((string) $parent->comment_author));
    $reply_author = esc_html(trim((string) $comment->comment_author));
    $post_title = esc_html(get_the_title($comment->comment_post_ID));
    $parent_content = sakura_prepare_comment_mail_content($parent->comment_content);
    $reply_content = sakura_prepare_comment_mail_content($comment->comment_content);
    $comment_link = esc_url(get_comment_link($parent));

    $content = '<p>Dear&nbsp;' . $recipient_name . '</p>'
        . '<h3 style="margin:0 0 14px;font-size:18px;font-weight:600;">您在文章《' . $post_title . '》上的评论有了新的回复</h3>'
        . '<p style="font-size:14px;">您发表的评论：</p>'
        . '<div style="margin:15px 0;padding:18px 20px;background:#f1f1f1;border:1px solid #ddd;">' . $parent_content . '</div>'
        . '<p style="font-size:14px;">' . $reply_author . ' 的回复：</p>'
        . '<div style="margin:15px 0;padding:18px 20px;background:#f1f1f1;border:1px solid #ddd;">' . $reply_content . '</div>';

    if ($comment_link) {
        $content .= '<p style="text-align:center;margin:24px 0 0;"><a style="display:inline-block;padding:10px 16px;border:2px solid #6c7575;color:#2f3333;text-decoration:none;" href="' . $comment_link . '" target="_blank" rel="noopener">点击查看回复的完整内容</a></p>';
    }

    return sakura_render_mail_template('您有一条新的评论回复', $content);
}

/**
 * Render the administrator test message.
 *
 * @param string $recipient Recipient email.
 * @param string $from Requested sender email.
 * @return string
 */
function sakura_render_test_mail($recipient, $from)
{
    $now = function_exists('wp_date') ? wp_date('Y-m-d H:i:s T') : date('Y-m-d H:i:s T');
    $rows = array(
        '站点名称' => sakura_get_mail_blog_name(),
        '站点地址' => home_url('/'),
        'Sakura 版本' => defined('SAKURA_VERSION') ? SAKURA_VERSION : 'unknown',
        '发送时间' => $now,
        '测试收件人' => $recipient,
        '主题请求的发件地址' => $from,
    );

    $table = '<table style="width:100%;border-collapse:collapse;margin:18px 0;">';
    foreach ($rows as $label => $value) {
        $table .= '<tr><th style="padding:9px 10px;border:1px solid #ddd;background:#f7f7f7;text-align:left;font-weight:600;">'
            . esc_html($label) . '</th><td style="padding:9px 10px;border:1px solid #ddd;word-break:break-word;">'
            . esc_html((string) $value) . '</td></tr>';
    }
    $table .= '</table>';

    $content = '<p>这是一封由 Sakura 主题设置发送的测试邮件，用于确认 WordPress 与邮件插件的连接是否被接受。</p>'
        . $table
        . '<p style="padding:12px 14px;background:#fff7e6;border-left:3px solid #f0a000;font-size:13px;">提示：后台显示发送成功只代表 <code>wp_mail()</code> 和邮件插件接受了请求，不代表邮件已经最终送达。</p>';

    return sakura_render_mail_template('Sakura 测试邮件', $content);
}

/**
 * Handle the settings-page test mail action.
 */
function sakura_handle_test_email()
{
    if (!current_user_can('edit_theme_options')) {
        wp_die(esc_html__('You are not allowed to send a test email.', 'sakura'), '', array('response' => 403));
    }
    check_admin_referer('sakura-send-test-email', '_sakura_test_nonce');

    $admin_email_value = get_option('admin_email', '');
    $recipient = is_scalar($admin_email_value) ? sanitize_email((string) $admin_email_value) : '';
    if (!is_email($recipient)) {
        return sakura_redirect_test_email_notice('error', __('站点管理员邮箱无效，无法发送测试邮件。', 'sakura'));
    }

    $from = sakura_get_mail_from_address();
    if ($from === '') {
        return sakura_redirect_test_email_notice('error', __('无法生成合法发件地址，请检查“发件地址前缀”和站点管理员邮箱设置。', 'sakura'));
    }

    $headers = sakura_get_mail_headers($from);
    if (!$headers) {
        return sakura_redirect_test_email_notice('error', __('发件地址无效，测试邮件未发送。', 'sakura'));
    }

    $result = sakura_send_html_mail(
        $recipient,
        sprintf(__('【%s】Sakura 测试邮件', 'sakura'), sakura_get_mail_blog_name()),
        sakura_render_test_mail($recipient, $from),
        $headers
    );

    if ($result['sent']) {
        return sakura_redirect_test_email_notice('success', sprintf(__('测试邮件已交给 WordPress/邮件插件处理，目标地址：%s。最终送达仍取决于邮件服务商。', 'sakura'), $recipient));
    }

    $error = $result['error'] ? ' ' . $result['error'] : '';
    return sakura_redirect_test_email_notice('error', __('测试邮件发送失败。', 'sakura') . $error);
}
add_action('admin_post_sakura_send_test_email', 'sakura_handle_test_email');

/**
 * Store a short-lived notice and return to the settings page.
 *
 * @param string $status success or error.
 * @param string $message Notice text.
 */
function sakura_redirect_test_email_notice($status, $message)
{
    $token = wp_generate_uuid4();
    $key = 'sakura_test_mail_notice_' . get_current_user_id() . '_' . $token;
    set_transient($key, array('status' => $status, 'message' => $message), MINUTE_IN_SECONDS);
    $url = add_query_arg('sakura_mail_test', rawurlencode($token), admin_url('themes.php?page=options-framework'));
    wp_safe_redirect($url);
    exit;
}

/**
 * Display the one-time test email result on the Sakura settings page.
 */
function sakura_test_email_notice()
{
    global $pagenow;
    if ('themes.php' !== $pagenow || empty($_GET['page']) || 'options-framework' !== sanitize_key(wp_unslash($_GET['page']))) {
        return;
    }
    if (empty($_GET['sakura_mail_test'])) {
        return;
    }

    $token = sanitize_text_field(wp_unslash($_GET['sakura_mail_test']));
    $key = 'sakura_test_mail_notice_' . get_current_user_id() . '_' . $token;
    $notice = get_transient($key);
    delete_transient($key);
    if (!is_array($notice) || empty($notice['message'])) {
        return;
    }

    $status = isset($notice['status']) && 'success' === $notice['status'] ? 'success' : 'error';
    printf(
        '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
        esc_attr($status),
        esc_html($notice['message'])
    );
}
add_action('admin_notices', 'sakura_test_email_notice');

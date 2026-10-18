<?php
/** 独立执行真实下载模块；网络与WordPress边界由有界夹具代替。 */
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});
$root = dirname(__DIR__, 2);
$temporary = sys_get_temp_dir() . '/sakura-costumes-' . bin2hex(random_bytes(8));
$uploads = $temporary . '/uploads';
$options = $requests = $hooks = $ranges = array();
$reference = str_repeat('a', 40);
$failure = '';
$allowed = $nonce = true;
$checks = 0;
class WP_Error {
    public $code, $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
class CostumeResponse extends Exception {
    public $data, $status;
    public function __construct($data, $status) { $this->data = $data; $this->status = $status; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text, $domain) { return $text; }
function akina_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function get_template_directory() { return $GLOBALS['root']; }
function sakura_remote_resource_ref() { return $GLOBALS['reference']; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_upload_dir($time, $create) {
    verify($create === false, '读取上传根不会创建日期目录');
    return array('basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://static.example.test/sites/42', 'error' => false);
}
function wp_mkdir_p($directory) { return mkdir($directory, 0755, true); }
function trailingslashit($value) { return rtrim($value, '/') . '/'; }
function esc_url_raw($value) { return $value; }
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
function wp_unslash($value) { return $value; }
function current_user_can($cap) { verify($cap === 'edit_theme_options', '使用主题设置权限'); return $GLOBALS['allowed']; }
function check_ajax_referer($action, $field, $die) { verify($action === 'sakura-live2d-costumes' && $field === 'nonce' && $die === false, 'nonce检查'); return $GLOBALS['nonce']; }
function wp_send_json_error($data, $status) { throw new CostumeResponse($data, $status); }
function wp_send_json_success($data) { throw new CostumeResponse($data, 200); }
function add_action($name, $callback) { $GLOBALS['hooks'][$name] = $callback; }
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
function wp_remote_retrieve_header($response, $name) { return $response['headers'][$name] ?? ''; }
function wp_safe_remote_get($url, $args) {
    global $requests, $failure, $root, $reference, $ranges;
    $prefix = 'https://cdn.jsdelivr.net/gh/ADDGM/sakura@' . $reference . '/';
    verify(strpos($url, $prefix . 'live2d/costumes/pio/textures/') === 0, '只请求固定CDN目录');
    verify($args['stream'] === true && $args['redirection'] === 0 && $args['timeout'] === 20, '流式有界请求且不跟随重定向');
    $requests[] = $url;
    if ($failure === 'network') return new WP_Error('offline', 'secret transport details');
    $full = file_get_contents($root . '/' . substr($url, strlen($prefix)));
    verify(preg_match('/^bytes=(\d+)-(\d+)$/', $args['headers']['Range'], $range) === 1, '请求明确的字节分片');
    $start = (int) $range[1]; $end = (int) $range[2]; $ranges[] = array($start, $end);
    verify($end - $start + 1 <= 262144 && $args['headers']['Accept-Encoding'] === 'identity', '256KiB分片及无压缩传输');
    $content = $failure === 'full' ? $full : substr($full, $start, $end - $start + 1);
    if ($failure === 'short') $content = substr($content, 0, -1);
    if ($failure === 'oversize') $content .= 'x';
    if ($failure === 'hash') $content[100] = chr(ord($content[100]) ^ 1);
    verify(strlen($content) <= $args['limit_response_size'], '限制响应体字节数');
    file_put_contents($args['filename'], $content);
    return array('response' => array('code' => $failure === 'redirect' ? 302 : ($failure === 'http' ? 503 : ($failure === 'full' ? 200 : 206))),
        'headers' => array('content-range' => 'bytes ' . ($failure === 'range' ? 1 : $start) . '-' . $end . '/' . strlen($full)));
}
function download_all($index) {
    for ($i = 0; $i < 10; $i++) {
        $result = sakura_live2d_costume_request('pio', 'download', $index);
        if (is_wp_error($result) || $result['complete']) return $result;
    }
    throw new RuntimeException('分片未在预期次数内完成');
}
function verify($condition, $message) { $GLOBALS['checks']++; if (!$condition) throw new RuntimeException($message); }
function remove_fixture($path, $base) {
    if (strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 && $path !== $base) throw new RuntimeException('越界清理');
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) as $child) if ($child !== '.' && $child !== '..') remove_fixture($path . DIRECTORY_SEPARATOR . $child, $base);
        rmdir($path);
    }
}
require $root . '/inc/live2d-costumes.php';
try {
    verify(isset($hooks['wp_ajax_sakura_live2d_resources']) && !isset($hooks['wp_ajax_nopriv_sakura_live2d_resources']), '只注册认证后台操作');
    verify(sakura_live2d_costume_source() === 'cdn', '默认CDN');
    $options = array('live2d_catalog' => 'https://example.test/catalog.json');
    verify(sakura_live2d_costume_source() === 'custom', '旧JSON选择保留');
    $options += array('live2d_b' => '1', 'live2d_i' => 'https://example.test/');
    verify(sakura_live2d_costume_source() === 'remote', '旧PNG优先级保留');
    foreach (array('cdn', 'local', 'bundled', 'custom', 'remote') as $mode) {
        $options['live2d_costume_source'] = $mode;
        verify(sakura_live2d_costume_source() === $mode, '显式来源覆盖旧设置');
    }
    $catalog = sakura_live2d_costume_catalog('pio');
    verify(count($catalog['costumes']) === 104 && array_sum(array_column($catalog['costumes'], 'bytes')) === 50456340, '104套清单及总大小');
    verify(sakura_live2d_costume_catalog('tia') === null && sakura_live2d_costume_catalog('../pio') === null, '模型隔离');
    $cdn = sakura_live2d_costume_urls('pio', 'cdn');
    verify(count($cdn) === 104 && strpos($cdn[0], '@' . $reference . '/') !== false && !$requests, '固定CDN候选无网络预取');
    verify(sakura_live2d_costume_urls('pio', 'custom') === array() && sakura_live2d_costume_urls('tia', 'cdn') === array(), '来源和模型互斥');
    $reference = '';
    verify(sakura_live2d_costume_urls('pio', 'cdn') === array(), '无来源构建回退内置');
    $reference = str_repeat('a', 40);
    $status = sakura_live2d_costume_request('pio', 'status');
    verify(count($status['missing']) === 104 && !$status['ready'] && !file_exists($temporary), '状态读取无文件写入');
    verify(sakura_live2d_costume_urls('pio', 'local') === array() && !file_exists($temporary), '未完成本地库回退内置且不创建目录');
    $_POST = array('model' => 'pio', 'mode' => 'download', 'index' => '0', 'url' => 'http://127.0.0.1/secret');
    $_REQUEST = array('nonce' => 'fixture');
    foreach (array(array(false, true), array(true, false)) as $auth) {
        list($allowed, $nonce) = $auth;
        try { sakura_live2d_costume_ajax(); throw new RuntimeException('未阻止未授权请求'); }
        catch (CostumeResponse $result) { verify($result->status === 403 && !$requests && !file_exists($temporary), '权限或nonce失败无写入/网络'); }
    }
    $allowed = $nonce = true;
    $_REQUEST['nonce'] = array('invalid');
    try { sakura_live2d_costume_ajax(); throw new RuntimeException('未拒绝数组nonce'); }
    catch (CostumeResponse $result) { verify($result->status === 403, '非法nonce类型被拒绝且无PHP诊断'); }
    $_REQUEST['nonce'] = 'fixture';
    foreach (array(
        array('model' => '../pio', 'mode' => 'clear'),
        array('model' => array('pio'), 'mode' => 'clear'),
        array('model' => 'pio', 'mode' => '../clear'),
    ) as $invalid) {
        $_POST = $invalid;
        try { sakura_live2d_costume_ajax(); throw new RuntimeException('未拒绝非法清理请求'); }
        catch (CostumeResponse $result) { verify($result->status === 400 && !$requests && !file_exists($temporary), '非法模型或操作不能创建或删除文件'); }
    }
    $_POST = array('model' => 'pio', 'mode' => 'download', 'index' => '0', 'url' => 'http://127.0.0.1/secret');
    foreach (array('network', 'http', 'redirect', 'short', 'oversize', 'hash', 'range') as $failure) {
        $result = download_all(0);
        verify(is_wp_error($result), '拒绝下载失败：' . $failure);
        $directory = sakura_live2d_costume_directory($catalog);
        verify(!glob($directory['path'] . '/textures/.part-*') && !sakura_live2d_costume_ready($directory, $catalog), '失败清理临时文件且不激活');
        verify(strpos($result->get_error_message(), $temporary) === false, '错误不泄露服务器路径');
    }
    $failure = '';
    try { sakura_live2d_costume_ajax(); } catch (CostumeResponse $result) { verify($result->status === 200, '合法AJAX下载'); }
    verify(sakura_live2d_costume_request('pio', 'status')['bytes'] === 262144, '单文件进度跨请求保留');
    verify(!is_file($directory['path'] . '/' . $catalog['costumes'][0]['texture']), '分片未完整时不安装PNG');
    verify(download_all(0)['complete'] === true && $ranges[count($ranges) - 1][0] > 0, '按字节偏移续传到完整文件');
    $before = count($requests);
    verify(sakura_live2d_costume_request('pio', 'download', 0)['complete'] === true && count($requests) === $before, '续传跳过已验证PNG');
    verify(count(sakura_live2d_costume_request('pio', 'status')['missing']) === 103, '恢复实际文件进度');
    verify(is_wp_error(sakura_live2d_costume_request('pio', 'finish')), '部分下载不能激活');
    verify(is_wp_error(sakura_live2d_costume_request('pio', 'download', null)) && is_wp_error(sakura_live2d_costume_request('pio', 'download', 999)), '拒绝非法索引');
    $lock = fopen($directory['path'] . '/.lock', 'c');
    flock($lock, LOCK_EX);
    verify(sakura_live2d_costume_request('pio', 'download', 1)->code === 'busy', '两个下载操作不同时写入');
    flock($lock, LOCK_UN); fclose($lock);
    $fake = $temporary . '/fake.png'; file_put_contents($fake, str_repeat('x', 100));
    verify(!sakura_live2d_costume_file_valid($fake, array('bytes' => 100, 'sha256' => hash_file('sha256', $fake))), '哈希正确的非PNG拒绝');
    // Linux CI covers symlink refusal; Windows without symlink privileges may skip it.
    $link = $directory['path'] . '/' . $catalog['costumes'][1]['texture'];
    if (@symlink($fake, $link)) {
        verify(sakura_live2d_costume_request('pio', 'download', 1)->code === 'storage', '拒绝符号链接目标');
        unlink($link);
    }
    // 清理必须保留清单外文件、自定义资源和其他版本，即使请求携带这些路径。
    $protected = array(
        $uploads . '/sakura-live2d/custom/catalog.json',
        dirname($directory['path']) . '/' . str_repeat('f', 64) . '/keep.png',
        $directory['path'] . '/textures/unlisted.png',
        $directory['path'] . '/textures/.chunk-user-file',
        $directory['path'] . '/.state-user-file',
    );
    foreach ($protected as $file) {
        if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
        file_put_contents($file, 'preserve custom data');
    }
    $owned = array(
        $directory['path'] . '/textures/.part-' . $catalog['costumes'][1]['sha256'],
        $directory['path'] . '/textures/.chunk-' . str_repeat('a', 24),
        $directory['path'] . '/.state-' . str_repeat('b', 24),
    );
    foreach ($owned as $file) file_put_contents($file, 'unfinished');
    $clearLink = $directory['path'] . '/textures/.part-' . $catalog['costumes'][2]['sha256'];
    if (@symlink($protected[0], $clearLink)) {
        verify(sakura_live2d_costume_request('pio', 'clear')->code === 'storage', '清理拒绝符号链接');
        verify(is_link($clearLink) && file_get_contents($protected[0]) === 'preserve custom data', '清理不删除链接或其目标');
        unlink($clearLink);
    } else echo "SKIP clear symlink fixture: unavailable on this host\n";
    $_POST = array('model' => 'pio', 'mode' => 'clear', 'path' => $protected[0], 'file' => '../custom/catalog.json', 'url' => 'http://127.0.0.1/secret');
    $before = count($requests);
    try { sakura_live2d_costume_ajax(); throw new RuntimeException('清理未返回响应'); }
    catch (CostumeResponse $result) { verify($result->status === 200 && count($requests) === $before, '清理忽略客户端路径和URL且不请求网络'); }
    verify(count(sakura_live2d_costume_request('pio', 'status')['missing']) === 104, '清除尚未启用的下载库');
    foreach ($owned as $file) verify(!file_exists($file), '清理受控分片及随机临时文件');
    foreach ($protected as $file) verify(file_get_contents($file) === 'preserve custom data', '清理保留范围外文件');
    for ($i = 0; $i < 104; $i++) verify(download_all($i)['complete'] === true, '下载完整库');
    verify(sakura_live2d_costume_request('pio', 'finish')['ready'] === true, '全库核验后激活');
    $local = sakura_live2d_costume_urls('pio', 'local');
    verify(count($local) === 104 && strpos($local[0], 'https://static.example.test/sites/42/sakura-live2d/managed/pio/') === 0, '本地URL遵循自定义上传配置');
    verify(count(sakura_live2d_costume_request('pio', 'restart')['missing']) === 104, '覆盖操作重新下载已有文件');
    $oldHash = hash_file('sha256', $directory['path'] . '/' . $catalog['costumes'][0]['texture']);
    verify(!sakura_live2d_costume_request('pio', 'download', 0)['complete'], '覆盖分片保留原文件');
    $failure = 'network';
    verify(is_wp_error(sakura_live2d_costume_request('pio', 'download', 0)) && hash_file('sha256', $directory['path'] . '/' . $catalog['costumes'][0]['texture']) === $oldHash, '覆盖失败不破坏现有外观');
    verify(sakura_live2d_costume_request('pio', 'clear') === true && sakura_live2d_costume_urls('pio', 'local') === $local && count(sakura_live2d_costume_request('pio', 'status')['missing']) === 0, '清除覆盖任务保留已启用库');
    sakura_live2d_costume_request('pio', 'restart');
    $failure = 'full';
    verify(download_all(0)['complete'], '服务器忽略Range时接受校验通过的完整200响应');
    $failure = '';
    verify(count(sakura_live2d_costume_request('pio', 'status')['missing']) === 103, '覆盖进度跨请求保留');
    for ($i = 1; $i < 104; $i++) verify(download_all($i)['complete'], '覆盖库续传');
    verify(sakura_live2d_costume_request('pio', 'finish')['ready'] && !is_file($directory['path'] . '/refresh.json'), '覆盖完成结束状态');
    $reference = '';
    verify(sakura_live2d_costume_urls('pio', 'local') === $local, '已安装本地库不依赖CDN引用');
    file_put_contents($directory['path'] . '/' . $catalog['costumes'][0]['texture'], 'broken');
    verify(sakura_live2d_costume_request('pio', 'status')['missing'] === array(0), '断点恢复重验损坏文件');
    echo 'PASS ' . $checks . " managed costume checks\n";
} finally {
    remove_fixture($temporary, $temporary);
    restore_error_handler();
}

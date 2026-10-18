<?php
// Included by live2d-managed.php inside its isolated fixture and cleanup boundary.
$historyRoot = dirname($directory['path']);
$networkBefore = count($requests);
$oldVersion = str_repeat('a', 64);
$otherVersion = str_repeat('b', 64);
function history_fixture($root, $version) {
    $path = $root . '/' . $version;
    mkdir($path . '/textures', 0755, true);
    file_put_contents($path . '/ready.json', json_encode(array('packVersion' => $version)));
    file_put_contents($path . '/.lock', '');
    file_put_contents($path . '/textures/sample.png', 'sample texture');
    file_put_contents($path . '/textures/.part-' . str_repeat('1', 64), 'part');
    file_put_contents($path . '/.state-' . str_repeat('2', 24), 'state');
    return $path;
}
$oldPath = history_fixture($historyRoot, $oldVersion);
$otherPath = history_fixture($historyRoot, $otherVersion);
$otherModel = history_fixture(dirname($historyRoot) . '/tia', $oldVersion);
$history = sakura_live2d_costume_history_request('pio', 'history');
$rows = array_column($history['versions'], null, 'version');
verify($rows[$catalog['packVersion']]['state'] === 'current', '当前版本即使有未知文件仍受保护');
verify($rows[$oldVersion]['state'] === 'available' && $rows[$oldVersion]['files'] === 5, '旧完成标记兼容且统计实际文件');
verify($rows[str_repeat('f', 64)]['state'] === 'blocked', '无完成标记的旧目录保留');
verify(strpos(json_encode($history), $uploads) === false && strpos(json_encode($history), 'sample.png') === false, '历史列表不泄露绝对路径和文件名');
verify(is_wp_error(sakura_live2d_costume_history_request('tia', 'history-delete', $oldVersion)), '无当前兼容清单的模型不能删除历史库');
foreach (array('history-preview', 'history-delete') as $mode) {
    verify(sakura_live2d_costume_history_request('pio', $mode, $catalog['packVersion'])->code === 'current', '预览和删除均保护当前版本');
    verify(is_wp_error(sakura_live2d_costume_history_request('pio', $mode, '../' . $oldVersion)), '版本遍历被拒绝');
}
$preview = sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion);
verify(!is_wp_error($preview), '持有锁时预览正常读取资源文件');
verify($preview['files'] === 5 && $preview['bytes'] === array_sum(array_map('filesize', array(
    $oldPath . '/ready.json', $oldPath . '/.lock', $oldPath . '/textures/sample.png',
    $oldPath . '/textures/.part-' . str_repeat('1', 64), $oldPath . '/.state-' . str_repeat('2', 24)))), '预览文件数及字节精确');
verify(is_file($oldPath . '/textures/sample.png'), '仅预览不删除');
verify(sakura_live2d_costume_history_request('pio', 'history-delete', $oldVersion)->code === 'changed', '没有确认票据不能删除');
verify(sakura_live2d_costume_history_request('pio', 'history-delete', $otherVersion, $preview['ticket'])->code === 'changed', '确认票据不能跨版本使用');
$nonce_user = 2;
verify(sakura_live2d_costume_history_request('pio', 'history-delete', $oldVersion, $preview['ticket'])->code === 'changed', '确认票据绑定用户');
$nonce_user = 1;
file_put_contents($oldPath . '/textures/sample.png', 'sample texturE');
verify(sakura_live2d_costume_history_request('pio', 'history-delete', $oldVersion, $preview['ticket'])->code === 'changed', '等长内容变化使预览失效');
verify(is_file($oldPath . '/textures/sample.png'), '失效确认不触碰文件');
$lock = fopen($oldPath . '/.lock', 'r+'); flock($lock, LOCK_EX);
try {
    verify(sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion)->code === 'busy', '预览尊重活动下载锁');
    verify(sakura_live2d_costume_history_request('pio', 'history-delete', $oldVersion, $preview['ticket'])->code === 'busy', '删除尊重活动下载锁');
    $busyRows = array_column(sakura_live2d_costume_history_request('pio', 'history')['versions'], null, 'version');
    verify($busyRows[$oldVersion]['state'] === 'busy', '列表标记繁忙版本不可清理');
} finally { flock($lock, LOCK_UN); fclose($lock); }
foreach (array('notes.txt', 'textures/custom.txt', 'textures/.chunk-custom') as $unknown) {
    file_put_contents($oldPath . '/' . $unknown, 'custom content');
    verify(is_wp_error(sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion)), '未知文件使整个版本保留');
    unlink($oldPath . '/' . $unknown);
}
mkdir($oldPath . '/textures/extra');
verify(is_wp_error(sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion)), '额外子目录不递归遍历');
rmdir($oldPath . '/textures/extra');
$link = $oldPath . '/textures/linked.png';
if (@symlink($protected[0], $link)) {
    verify(is_wp_error(sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion)), '符号链接拒绝整个版本');
    unlink($link);
    $linkedVersion = str_repeat('c', 64);
    symlink($otherPath, $historyRoot . '/' . $linkedVersion);
    verify(is_wp_error(sakura_live2d_costume_history_request('pio', 'history-preview', $linkedVersion)), '版本目录链接不跟随');
    unlink($historyRoot . '/' . $linkedVersion);
} else echo "SKIP history symlink fixture: unavailable on this host\n";
if (@link($protected[0], $link)) {
    verify(is_wp_error(sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion)), '共享硬链接文件保留');
    unlink($link);
}
$preview = sakura_live2d_costume_history_request('pio', 'history-preview', $oldVersion);
$_POST = array('model' => 'pio', 'mode' => 'history-delete', 'version' => $oldVersion, 'ticket' => $preview['ticket'], 'path' => $protected[0]);
foreach (array(array(false, true), array(true, false)) as $auth) {
    list($allowed, $nonce) = $auth;
    try { sakura_live2d_costume_ajax(); } catch (CostumeResponse $response) {
        verify($response->status === 403 && is_file($oldPath . '/ready.json'), '历史删除也要求设置权限和请求nonce');
    }
}
$allowed = $nonce = true;
$_POST['version'] = array($oldVersion);
try { sakura_live2d_costume_ajax(); } catch (CostumeResponse $response) { verify($response->status === 400, '非法版本类型不产生PHP诊断'); }
$_POST['version'] = $oldVersion;
try { sakura_live2d_costume_ajax(); } catch (CostumeResponse $response) {
    verify($response->status === 200 && $response->data['bytes'] === $preview['bytes'], '带确认票据清理成功并报告实际范围');
}
clearstatcache();
verify(!file_exists($oldPath), '成功后旧版本目录完全删除');
verify(is_file($otherPath . '/textures/sample.png') && is_file($otherModel . '/textures/sample.png'), '其他版本及其他模型保留');
foreach ($protected as $file) verify(file_get_contents($file) === 'preserve custom data', '自定义内容与原清理边界不变');
verify(is_file($directory['path'] . '/ready.json') && count($requests) === $networkBefore, '当前库保留且全部历史操作没有网络请求');
// A previous cleanup may have removed textures before another filesystem operation failed.
foreach (glob($otherPath . '/textures/*') as $file) unlink($file);
unlink($otherPath . '/textures/.part-' . str_repeat('1', 64)); rmdir($otherPath . '/textures');
$retry = sakura_live2d_costume_history_request('pio', 'history-preview', $otherVersion);
verify(!is_wp_error($retry) && $retry['files'] === 3, '部分清理后剩余版本可重新预览');
verify(!is_wp_error(sakura_live2d_costume_history_request('pio', 'history-delete', $otherVersion, $retry['ticket'])), '重新预览后可清除剩余受控文件');

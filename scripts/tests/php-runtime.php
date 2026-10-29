<?php
/** 独立执行真实解析器与cURL路径，PHP弃用和警告均视为失败。 */
if (PHP_SAPI !== 'cli') exit(1);
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

require dirname(__DIR__, 2) . '/inc/Parsedown.php';
require dirname(__DIR__, 2) . '/inc/classes/Meting.php';

$cases = array(
    array("Title\n=====", '<h1>Title</h1>'),
    array("Subtitle\n--------", '<h2>Subtitle</h2>'),
    array('===', '<p>===</p>'),
    array('|---|', '<p>|---|</p>'),
    array("| A | B |\n| --- | --- |\n| 1 | 2 |", "<table>\n<thead>\n<tr>\n<th>A</th>\n<th>B</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td>1</td>\n<td>2</td>\n</tr>\n</tbody>\n</table>"),
    array('**bold** and [link](https://example.test)', '<p><strong>bold</strong> and <a href="https://example.test">link</a></p>'),
);
foreach ($cases as $case) {
    if ((new Parsedown())->text($case[0]) !== $case[1]) {
        throw new RuntimeException('Markdown输出发生变化：' . $case[0]);
    }
}

if (!extension_loaded('curl')) throw new RuntimeException('此检查需要PHP cURL扩展。');
$file = tempnam(sys_get_temp_dir(), 'sakura-php-');
try {
    $body = '{"fixture":"local transfer"}';
    file_put_contents($file, $body);
    $path = str_replace('\\', '/', realpath($file));
    // 编码文件名中的URL保留字符，保留目录分隔符与Windows盘符。
    $path = str_replace(array('%2F', '%3A'), array('/', ':'), rawurlencode($path));
    $url = 'file://' . ($path[0] === '/' ? '' : '/') . $path;
    $meting = new \Sakura\API\Meting('netease');
    $curl = new ReflectionMethod($meting, 'curl');
    if (PHP_VERSION_ID < 80100) $curl->setAccessible(true);
    if ($curl->invoke($meting, $url) !== $meting || $meting->raw !== $body || $meting->error !== 0) {
        throw new RuntimeException('Meting真实cURL读取或返回值异常。');
    }
} finally {
    if (is_file($file)) unlink($file);
    restore_error_handler();
}
echo 'PHP运行时兼容检查通过：' . (count($cases) + 1) . "项，PHP " . PHP_VERSION . "。\n";

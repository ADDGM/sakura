<?php
/**
 * 使用 PHP 内置实现把 PO 文件编译为 WordPress 可读取的 MO 文件。
 *
 * 该脚本只读取翻译资源，不会读取主题设置、Cookie 或任何运行时凭据。
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "此脚本只能在命令行运行。\n");
    exit(1);
}

function po_unquote($value)
{
    $value = trim($value);
    if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
        $decoded = json_decode($value, true);
        if (is_string($decoded)) {
            return $decoded;
        }
        return stripcslashes(substr($value, 1, -1));
    }
    return '';
}

function po_parse($contents)
{
    $entries = array();
    $entry = null;
    $field = null;
    $fieldIndex = null;
    $obsolete = false;
    $fuzzy = false;

    $flush = static function () use (&$entries, &$entry, &$field, &$fieldIndex, &$obsolete, &$fuzzy) {
        if (is_array($entry) && isset($entry['msgid']) && !$obsolete && !$fuzzy) {
            $entries[] = $entry;
        }
        $entry = null;
        $field = null;
        $fieldIndex = null;
        $obsolete = false;
        $fuzzy = false;
    };

    foreach (preg_split("/\r?\n/", $contents) as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flush();
            continue;
        }
        if (strpos($trimmed, '#~') === 0) {
            $obsolete = true;
            continue;
        }
        if (strpos($trimmed, '#,') === 0 && strpos($trimmed, 'fuzzy') !== false) {
            $fuzzy = true;
            continue;
        }
        if ($trimmed[0] === '#') {
            continue;
        }

        if (preg_match('/^msgctxt\s+(.+)$/', $trimmed, $matches)) {
            if (!is_array($entry)) {
                $entry = array('msgstr' => array());
            }
            $entry['msgctxt'] = po_unquote($matches[1]);
            $field = 'msgctxt';
            $fieldIndex = null;
            continue;
        }
        if (preg_match('/^msgid_plural\s+(.+)$/', $trimmed, $matches)) {
            if (!is_array($entry)) {
                $entry = array('msgstr' => array());
            }
            $entry['msgid_plural'] = po_unquote($matches[1]);
            $field = 'msgid_plural';
            $fieldIndex = null;
            continue;
        }
        if (preg_match('/^msgid\s+(.+)$/', $trimmed, $matches)) {
            if (is_array($entry) && isset($entry['msgid'])) {
                $flush();
            }
            if (!is_array($entry)) {
                $entry = array();
            }
            $entry['msgid'] = po_unquote($matches[1]);
            $entry['msgstr'] = array();
            $field = 'msgid';
            $fieldIndex = null;
            continue;
        }
        if (preg_match('/^msgstr(?:\[(\d+)\])?\s+(.+)$/', $trimmed, $matches)) {
            if (!is_array($entry)) {
                $entry = array('msgstr' => array());
            }
            $field = 'msgstr';
            $fieldIndex = $matches[1] === '' ? 0 : (int) $matches[1];
            $entry['msgstr'][$fieldIndex] = po_unquote($matches[2]);
            continue;
        }
        if (preg_match('/^".*"$/', $trimmed) && is_array($entry) && $field !== null) {
            $value = po_unquote($trimmed);
            if ($field === 'msgstr') {
                $entry['msgstr'][$fieldIndex] = ($entry['msgstr'][$fieldIndex] ?? '') . $value;
            } else {
                $entry[$field] = ($entry[$field] ?? '') . $value;
            }
        }
    }
    $flush();
    return $entries;
}

function po_entries_from_file($path)
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("无法读取 PO 文件：{$path}");
    }
    return po_parse($contents);
}

function po_to_mo_entries($entries)
{
    $compiled = array();
    foreach ($entries as $entry) {
        $msgid = (string) ($entry['msgid'] ?? '');
        $translations = $entry['msgstr'] ?? array();
        if ($msgid === '') {
            $header = (string) ($translations[0] ?? '');
            if ($header !== '') {
                $compiled[''] = $header;
            }
            continue;
        }
        $hasPlural = isset($entry['msgid_plural']);
        if ($hasPlural) {
            if (!isset($translations[0]) || $translations[0] === '') {
                continue;
            }
            ksort($translations, SORT_NUMERIC);
            foreach ($translations as $translation) {
                if ($translation === '') {
                    continue 2;
                }
            }
            $original = $msgid . "\0" . $entry['msgid_plural'];
            $translated = implode("\0", $translations);
        } else {
            $translated = (string) ($translations[0] ?? '');
            if ($translated === '') {
                continue;
            }
            $original = $msgid;
        }
        if (isset($entry['msgctxt'])) {
            $original = $entry['msgctxt'] . "\004" . $original;
        }
        $compiled[$original] = $translated;
    }
    if (!isset($compiled[''])) {
        $compiled[''] = '';
    }
    return $compiled;
}

function mo_pack_u32($value)
{
    return pack('V', $value);
}

function mo_build($entries)
{
    ksort($entries, SORT_STRING);
    $count = count($entries);
    $originalTableOffset = 28;
    $translationTableOffset = $originalTableOffset + ($count * 8);
    $stringOffset = $translationTableOffset + ($count * 8);
    $originalTable = '';
    $translationTable = '';
    $originalStrings = '';
    $translationStrings = '';
    $offset = $stringOffset;

    foreach ($entries as $original => $translation) {
        $original = (string) $original;
        $originalTable .= mo_pack_u32(strlen($original)) . mo_pack_u32($offset);
        $originalStrings .= $original . "\0";
        $offset += strlen($original) + 1;
    }
    $offset = $stringOffset + strlen($originalStrings);
    foreach ($entries as $translation) {
        $translation = (string) $translation;
        $translationTable .= mo_pack_u32(strlen($translation)) . mo_pack_u32($offset);
        $translationStrings .= $translation . "\0";
        $offset += strlen($translation) + 1;
    }

    return pack('V7', 0x950412de, 0, $count, $originalTableOffset, $translationTableOffset, 0, 0)
        . $originalTable . $translationTable . $originalStrings . $translationStrings;
}

function compile_translation_file($poPath)
{
    return mo_build(po_to_mo_entries(po_entries_from_file($poPath)));
}

function run_translation_self_test()
{
    $po = <<<'PO'
msgid ""
msgstr ""
"Language: zh_CN\\n"

#, fuzzy
msgid "忽略"
msgstr "不应写入"

msgctxt "menu"
msgid "Open"
msgstr ""

msgctxt "menu"
msgid "Close"
msgstr "关"

msgid "one"
msgid_plural "many"
msgstr[0] "一个"
msgstr[1] "多个"
PO;
    $entries = po_to_mo_entries(po_parse($po));
    if (count($entries) !== 3 || !isset($entries['']) || !isset($entries["menu\004Close"])) {
        throw new RuntimeException('PO 编译自测失败：context、header 或空译文处理不正确。');
    }
    $mo = mo_build($entries);
    if (strlen($mo) < 28 || unpack('V', substr($mo, 0, 4))[1] !== 0x950412de) {
        throw new RuntimeException('PO 编译自测失败：MO 文件头不正确。');
    }
    echo "翻译编译自测通过。\n";
}

$options = getopt('', array('dir::', 'check', 'self-test'));
if (isset($options['self-test'])) {
    run_translation_self_test();
    exit(0);
}

$directory = isset($options['dir']) && $options['dir'] !== false
    ? $options['dir']
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'languages';
$poFiles = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.po');
$compiled = 0;
$errors = array();
foreach ($poFiles as $poPath) {
    $locale = basename($poPath, '.po');
    if ($locale === 'sakura') {
        continue;
    }
    $moPath = dirname($poPath) . DIRECTORY_SEPARATOR . $locale . '.mo';
    try {
        $mo = compile_translation_file($poPath);
        if (isset($options['check'])) {
            $existing = file_get_contents($moPath);
            if ($existing === false || !hash_equals($existing, $mo)) {
                $errors[] = "{$locale}.mo 与 {$locale}.po 不一致。";
            }
        } else {
            if (file_put_contents($moPath, $mo) === false) {
                $errors[] = "无法写入 {$moPath}。";
            }
        }
        $compiled++;
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

if (!$compiled) {
    $errors[] = "目录中没有可编译的 PO 文件：{$directory}";
}
if ($errors) {
    foreach ($errors as $error) {
        fwrite(STDERR, "- {$error}\n");
    }
    exit(1);
}
echo (isset($options['check']) ? '翻译 MO 检查通过' : '翻译 MO 编译完成') . "：{$compiled} 个文件。\n";

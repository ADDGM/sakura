<?php
/**
 * 读取、更新并校验 Sakura 主题头元数据。
 */

declare(strict_types=1);

const SAKURA_THEME_EXPECTED_HEADERS = array(
    'Theme Name' => 'Sakura',
    'Author' => 'ADDGM',
    'Requires at least' => '7.0',
    'Tested up to' => '7.1',
    'Requires PHP' => '8.0',
);

const SAKURA_THEME_VERSION_PATTERN = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:dev|beta)\.(0|[1-9][0-9]*))?$/';

function sakura_theme_argument(array $arguments, string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($arguments as $argument) {
        if (strpos($argument, $prefix) === 0) {
            return substr($argument, strlen($prefix));
        }
    }
    return $default;
}

function sakura_theme_read_file(string $file): string
{
    if ($file === '' || !is_file($file) || !is_readable($file)) {
        throw new RuntimeException('无法读取主题样式文件：' . ($file !== '' ? $file : '未提供路径'));
    }

    $content = file_get_contents($file);
    if ($content === false) {
        throw new RuntimeException('读取主题样式文件失败：' . $file);
    }
    return $content;
}

function sakura_theme_parse_headers(string $content): array
{
    $headerBlock = substr($content, 0, 8192);
    $fields = array_merge(array('Version' => ''), SAKURA_THEME_EXPECTED_HEADERS);
    $headers = array();

    foreach (array_keys($fields) as $field) {
        $pattern = '/^[ \t]*' . preg_quote($field, '/') . ':[ \t]*(.*?)[ \t]*\r?$/mi';
        if (preg_match($pattern, $headerBlock, $matches)) {
            $headers[$field] = trim($matches[1]);
        }
    }
    return $headers;
}

function sakura_theme_version_core(string $version): string
{
    if (!preg_match(SAKURA_THEME_VERSION_PATTERN, $version, $matches)) {
        throw new RuntimeException('主题版本格式无效：' . $version);
    }
    return $matches[1] . '.' . $matches[2] . '.' . $matches[3];
}

function sakura_theme_validate_headers(array $headers, string $expectedVersion = ''): void
{
    $errors = array();
    foreach (SAKURA_THEME_EXPECTED_HEADERS as $field => $expected) {
        $actual = $headers[$field] ?? '';
        if ($actual !== $expected) {
            $errors[] = $field . ' 应为 `' . $expected . '`，实际为 `' . ($actual !== '' ? $actual : '缺失') . '`';
        }
    }

    $version = $headers['Version'] ?? '';
    if (!preg_match(SAKURA_THEME_VERSION_PATTERN, $version)) {
        $errors[] = 'Version 格式无效：`' . ($version !== '' ? $version : '缺失') . '`';
    } elseif ($expectedVersion !== '' && $version !== $expectedVersion) {
        $errors[] = 'Version 应为 `' . $expectedVersion . '`，实际为 `' . $version . '`';
    }

    if ($errors) {
        throw new RuntimeException("主题头校验失败：\n- " . implode("\n- ", $errors));
    }
}

function sakura_theme_update_version(string $file, string $version): void
{
    sakura_theme_version_core($version);
    $content = sakura_theme_read_file($file);
    $updated = preg_replace('/^[ \t]*Version:[^\r\n]*/mi', 'Version: ' . $version, $content, 1, $count);
    if ($updated === null || $count !== 1) {
        throw new RuntimeException('主题样式文件必须包含且只能更新一个 Version 字段：' . $file);
    }
    if (file_put_contents($file, $updated) === false) {
        throw new RuntimeException('无法写入主题版本：' . $file);
    }
}

function sakura_theme_load_and_validate(string $file, string $expectedVersion = ''): array
{
    $headers = sakura_theme_parse_headers(sakura_theme_read_file($file));
    sakura_theme_validate_headers($headers, $expectedVersion);
    return $headers;
}

function sakura_theme_metadata_self_test(): int
{
    $file = tempnam(sys_get_temp_dir(), 'sakura-theme-');
    if ($file === false) {
        fwrite(STDERR, "主题元数据自测无法创建临时文件。\n");
        return 1;
    }

    $content = "/*\r\n"
        . "Theme Name: Sakura\r\n"
        . "Author: ADDGM\r\n"
        . "Version: 3.5.0\r\n"
        . "Requires at least: 7.0\r\n"
        . "Tested up to: 7.1\r\n"
        . "Requires PHP: 8.0\r\n"
        . "*/\r\n";

    try {
        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException('无法写入自测文件。');
        }
        $headers = sakura_theme_load_and_validate($file, '3.5.0');
        if (($headers['Version'] ?? '') !== '3.5.0' || sakura_theme_version_core('3.5.0-beta.2') !== '3.5.0') {
            throw new RuntimeException('主题版本读取或核心版本解析失败。');
        }

        sakura_theme_update_version($file, '3.5.0-dev.12');
        sakura_theme_load_and_validate($file, '3.5.0-dev.12');
        sakura_theme_update_version($file, '3.5.0-beta.2');
        sakura_theme_load_and_validate($file, '3.5.0-beta.2');
    } catch (Throwable $exception) {
        fwrite(STDERR, '主题元数据自测失败：' . $exception->getMessage() . "\n");
        return 1;
    } finally {
        @unlink($file);
    }

    echo "主题元数据自测通过。\n";
    return 0;
}

function sakura_theme_metadata_cli(array $arguments): int
{
    if (in_array('--self-test', $arguments, true)) {
        return sakura_theme_metadata_self_test();
    }

    $command = $arguments[0] ?? '';
    $file = sakura_theme_argument($arguments, 'file');
    $version = sakura_theme_argument($arguments, 'version');

    try {
        if ($command === 'source-version') {
            $headers = sakura_theme_load_and_validate($file);
            $sourceVersion = $headers['Version'];
            if (sakura_theme_version_core($sourceVersion) !== $sourceVersion) {
                throw new RuntimeException('源码 Version 必须是稳定的 X.Y.Z 基准版本：' . $sourceVersion);
            }
            echo $sourceVersion . "\n";
            return 0;
        }

        if ($command === 'check-release-base') {
            if ($version === '') {
                throw new RuntimeException('check-release-base 必须提供 --version。');
            }
            $headers = sakura_theme_load_and_validate($file);
            $sourceVersion = $headers['Version'];
            $releaseCore = sakura_theme_version_core($version);
            if ($sourceVersion !== $releaseCore) {
                throw new RuntimeException('发布版本与源码基准不一致：源码为 ' . $sourceVersion . '，发布核心版本为 ' . $releaseCore);
            }
            echo '已验证发布核心版本：' . $releaseCore . "\n";
            return 0;
        }

        if ($command === 'prepare') {
            if ($version === '') {
                throw new RuntimeException('prepare 必须提供 --version。');
            }
            sakura_theme_update_version($file, $version);
            sakura_theme_load_and_validate($file, $version);
            echo '已写入并验证主题版本：' . $version . "\n";
            return 0;
        }

        if ($command === 'verify') {
            if ($version === '') {
                throw new RuntimeException('verify 必须提供 --version。');
            }
            sakura_theme_load_and_validate($file, $version);
            echo '已验证主题包元数据：' . $version . "\n";
            return 0;
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        return 2;
    }

    fwrite(STDERR, "用法：\n");
    fwrite(STDERR, "  php scripts/theme-metadata.php source-version --file=style.css\n");
    fwrite(STDERR, "  php scripts/theme-metadata.php check-release-base --file=style.css --version=X.Y.Z[-beta.N]\n");
    fwrite(STDERR, "  php scripts/theme-metadata.php prepare --file=style.css --version=X.Y.Z[-dev.N|-beta.N]\n");
    fwrite(STDERR, "  php scripts/theme-metadata.php verify --file=style.css --version=X.Y.Z[-dev.N|-beta.N]\n");
    return 2;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(sakura_theme_metadata_cli(array_slice($argv, 1)));
}

<?php
/** 安装提交消息校验，不覆盖其他工具的钩子或修改 Git 配置。 */
declare(strict_types=1);

require_once __DIR__ . '/validate-commit-messages.php';

try {
    $root = trim(sakura_run_git_command(array('git', 'rev-parse', '--show-toplevel')));
    chdir($root);
    $configNames = sakura_run_git_command(array('git', 'config', '--name-only', '--list'));
    if (preg_match('/^core\.hookspath$/mi', $configNames)) {
        throw new RuntimeException('检测到 core.hooksPath；请将消息校验接入已有钩子，不自动修改此配置。');
    }
    $hook = trim(sakura_run_git_command(array('git', 'rev-parse', '--path-format=absolute', '--git-path', 'hooks/commit-msg')));
    $php = "'" . str_replace("'", "'\"'\"'", str_replace('\\', '/', PHP_BINARY)) . "'";
    $content = "#!/bin/sh\n# Sakura commit message validation\nexec " . $php
        . ' scripts/validate-commit-messages.php "--message-file=$1"' . "\n";
    if (is_link($hook)) {
        throw new RuntimeException('commit-msg 是符号链接，拒绝覆盖。');
    }
    if (file_exists($hook)) {
        if (!is_file($hook) || file_get_contents($hook) !== $content) {
            throw new RuntimeException('commit-msg 已存在且内容不同，拒绝覆盖；请手动串接校验。');
        }
        echo "commit-msg 已安装，内容一致。\n";
        exit(0);
    }
    $directory = dirname($hook);
    if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
        throw new RuntimeException('无法创建 hooks 目录。');
    }
    // 排他创建避免检查后覆盖其他进程刚写入的钩子。
    $stream = fopen($hook, 'x');
    if ($stream === false) {
        throw new RuntimeException('无法创建 commit-msg。');
    }
    $written = fwrite($stream, $content);
    fclose($stream);
    if ($written !== strlen($content) || !chmod($hook, 0755)) {
        throw new RuntimeException('commit-msg 写入或权限设置失败，请检查该文件后重试。');
    }
    echo "已安装 commit-msg；保留原有 pre-commit/post-commit 钩子。\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

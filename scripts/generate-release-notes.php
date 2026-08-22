<?php
/**
 * 根据两个 Git 引用之间的中文提交生成 Release 说明。
 */

declare(strict_types=1);

require_once __DIR__ . '/validate-commit-messages.php';

const SAKURA_RELEASE_SECTIONS = array(
    '新增' => '新增功能',
    '修复' => '问题修复',
    '兼容' => '兼容性更新',
    '优化' => '性能与体验优化',
    '重构' => '代码重构',
    '文档' => '文档更新',
    '构建' => '构建与发布',
    '测试' => '测试更新',
    '发布' => '版本发布',
);

function sakura_release_argument(string $name, string $default = ''): string
{
    $prefix = '--' . $name . '=';
    foreach ($GLOBALS['argv'] as $argument) {
        if (strpos($argument, $prefix) === 0) {
            return substr($argument, strlen($prefix));
        }
    }
    return $default;
}

function sakura_release_git_command(string $arguments): string
{
    $lines = array();
    $exitCode = 0;
    exec('git ' . $arguments . ' 2>&1', $lines, $exitCode);
    $output = implode("\n", $lines);
    if ($exitCode !== 0) {
        throw new RuntimeException('Git 命令执行失败：' . ($output !== '' ? $output : $arguments));
    }
    return $output;
}

function sakura_release_commits(string $range): array
{
    $output = sakura_release_git_command('log --no-merges --format=%H%x09%s%x09%an ' . escapeshellarg($range));

    $commits = array();
    foreach (preg_split('/\R/', trim($output)) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line, 3);
        if (count($parts) !== 3) {
            continue;
        }
        $parsed = sakura_parse_commit_title($parts[1]);
        $commits[] = array(
            'hash' => $parts[0],
            'title' => $parts[1],
            'author' => $parts[2],
            'parsed' => $parsed,
        );
    }
    return $commits;
}

function sakura_release_files(string $previous, string $tag): array
{
    $output = sakura_release_git_command('diff --name-only ' . escapeshellarg($previous) . ' ' . escapeshellarg($tag));
    return array_values(array_filter(preg_split('/\R/', trim($output))));
}

function sakura_release_file_summary(array $files): string
{
    if (!$files) {
        return '未检测到文件差异。';
    }

    $groups = array(
        '主题核心与模板' => 0,
        'REST、接口与数据' => 0,
        '样式与前端资源' => 0,
        '构建与文档' => 0,
        '其他文件' => 0,
    );
    foreach ($files as $file) {
        if (preg_match('/^(functions\.php|header\.php|footer\.php|404\.php|single\.php|page.*\.php|archive\.php|author\.php|search\.php|comments\.php|tpl\/|layouts\/|user\/)/', $file)) {
            $groups['主题核心与模板']++;
        } elseif (preg_match('/^(inc\/|options\.php)/', $file)) {
            $groups['REST、接口与数据']++;
        } elseif (preg_match('/^(style\.css|cdn\/|js\/|images\/)/', $file)) {
            $groups['样式与前端资源']++;
        } elseif (preg_match('/^(\.github\/|README|LICENSE|scripts\/|\.zcf\/)/', $file)) {
            $groups['构建与文档']++;
        } else {
            $groups['其他文件']++;
        }
    }
    $summary = array();
    foreach ($groups as $name => $count) {
        if ($count > 0) {
            $summary[] = $name . '（' . $count . ' 个文件）';
        }
    }
    return implode('、', $summary);
}

function sakura_render_release_notes(string $tag, string $previous, array $commits, array $files, string $repository, string $environment, bool $prerelease): string
{
    $sections = array();
    foreach ($commits as $commit) {
        $type = $commit['parsed']['type'] ?? '其他';
        $sections[$type][] = $commit;
    }

    $lines = array(
        '# Sakura ' . $tag . ' 更新记录',
        '',
        '> 本记录由版本区间 `' . $previous . '..' . $tag . '` 的中文 Git 提交自动生成。',
        '',
        '## 变更摘要',
        '',
        '- 变更文件：' . sakura_release_file_summary($files),
        '- 提交数量：' . count($commits),
        '',
    );

    foreach (SAKURA_RELEASE_SECTIONS as $type => $title) {
        if (empty($sections[$type])) {
            continue;
        }
        $lines[] = '## ' . $title;
        $lines[] = '';
        foreach ($sections[$type] as $commit) {
            $shortHash = substr($commit['hash'], 0, 7);
            $scope = $commit['parsed']['scope'] !== '' ? '（' . $commit['parsed']['scope'] . '）' : '';
            $link = $repository !== '' ? ' [' . $shortHash . '](https://github.com/' . $repository . '/commit/' . $commit['hash'] . ')' : ' `' . $shortHash . '`';
            $lines[] = '- ' . $scope . $commit['parsed']['summary'] . $link;
        }
        $lines[] = '';
    }

    if (!empty($sections['其他'])) {
        $lines[] = '## 其他变更';
        $lines[] = '';
        foreach ($sections['其他'] as $commit) {
            $shortHash = substr($commit['hash'], 0, 7);
            $link = $repository !== '' ? ' [' . $shortHash . '](https://github.com/' . $repository . '/commit/' . $commit['hash'] . ')' : ' `' . $shortHash . '`';
            $lines[] = '- ' . $commit['title'] . $link;
        }
        $lines[] = '';
    }

    $lines[] = '## 目标环境';
    $lines[] = '';
    $lines[] = '- ' . $environment;
    $lines[] = '- PHP 8.0、8.1、8.2';
    $lines[] = '';
    $lines[] = '## 升级提示';
    $lines[] = '';
    $lines[] = '- 升级前请备份数据库、主题设置和 `wp-content/uploads`。';
    $lines[] = $prerelease ? '- 此版本为测试版，不建议直接用于生产环境。' : '- 此版本为正式版，请先在测试服务器验证主题设置和外部服务。';
    $lines[] = '';
    return implode("\n", $lines);
}

function sakura_release_self_test(): int
{
    $commits = array(
        array('hash' => str_repeat('a', 40), 'title' => '兼容(WordPress): 修复标题 API', 'author' => '测试', 'parsed' => sakura_parse_commit_title('兼容(WordPress): 修复标题 API')),
        array('hash' => str_repeat('b', 40), 'title' => '文档: 更新升级说明', 'author' => '测试', 'parsed' => sakura_parse_commit_title('文档: 更新升级说明')),
    );
    $notes = sakura_render_release_notes('v3.5.0', 'v3.4.0', $commits, array('functions.php', 'README.md'), 'example/sakura', 'WordPress 7.1', false);
    if (strpos($notes, '兼容性更新') === false || strpos($notes, '修复标题 API') === false || strpos($notes, '构建与文档（1 个文件）') === false) {
        fwrite(STDERR, "Release 说明自测失败。\n");
        return 1;
    }
    $emptyNotes = sakura_render_release_notes('v3.5.1-beta.1', 'v3.5.0', array(), array(), '', 'WordPress 7.0、7.1', true);
    if (strpos($emptyNotes, '提交数量：0') === false || strpos($emptyNotes, '未检测到文件差异') === false || strpos($emptyNotes, '此版本为测试版') === false) {
        fwrite(STDERR, "Release 空变更区间自测失败。\n");
        return 1;
    }
    echo "Release 说明自测通过。\n";
    return 0;
}

if (in_array('--self-test', $argv, true)) {
    exit(sakura_release_self_test());
}

$tag = sakura_release_argument('tag');
$previous = sakura_release_argument('previous-tag');
$output = sakura_release_argument('output', 'release-notes.zh-CN.md');
$repository = sakura_release_argument('repository', getenv('GITHUB_REPOSITORY') ?: '');
$environment = sakura_release_argument('environment', 'WordPress 7.0、7.1');
$prerelease = in_array('--prerelease', $argv, true);

if ($tag === '' || $previous === '' || $output === '') {
    fwrite(STDERR, "用法：php scripts/generate-release-notes.php --tag=vX.Y.Z --previous-tag=<引用> [--output=文件] [--repository=所有者/仓库]\n");
    exit(2);
}

try {
    $commits = sakura_release_commits($previous . '..' . $tag);
    $files = sakura_release_files($previous, $tag);
    $notes = sakura_render_release_notes($tag, $previous, $commits, $files, $repository, $environment, $prerelease);
    if (file_put_contents($output, $notes) === false) {
        throw new RuntimeException('无法写入 Release 说明：' . $output);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
}

echo "已生成中文 Release 说明：{$output}\n";

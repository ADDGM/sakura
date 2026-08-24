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
    foreach (sakura_split_git_lines($output) as $line) {
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
    return array_values(array_filter(sakura_split_git_lines($output)));
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

function sakura_release_trigger_label(string $trigger): string
{
    $labels = array(
        'push' => '标签推送（push）',
        'workflow_dispatch' => '手动触发（workflow_dispatch）',
    );
    return $labels[$trigger] ?? ($trigger !== '' ? $trigger : '未提供');
}

function sakura_release_metadata(array $release, string $key, string $default = '未提供'): string
{
    $value = trim((string) ($release[$key] ?? ''));
    return $value !== '' ? $value : $default;
}

function sakura_render_release_notes(string $tag, string $previous, array $commits, array $files, string $repository, array $release, bool $prerelease): string
{
    $sections = array();
    foreach ($commits as $commit) {
        $type = $commit['parsed']['type'] ?? '其他';
        $sections[$type][] = $commit;
    }

    $version = sakura_release_metadata($release, 'version', strpos($tag, 'v') === 0 ? substr($tag, 1) : $tag);
    $publishedAt = sakura_release_metadata($release, 'published_at');
    $trigger = sakura_release_trigger_label(sakura_release_metadata($release, 'trigger', ''));
    $workflowUrl = sakura_release_metadata($release, 'workflow_url', '');
    $runNumber = sakura_release_metadata($release, 'run_number', '');
    $sourceSha = sakura_release_metadata($release, 'source_sha', '');
    $releaseType = $prerelease ? '测试版（Prerelease）' : '正式版';
    $workflow = $workflowUrl !== ''
        ? '[#' . ($runNumber !== '' ? $runNumber : '查看运行') . '](' . $workflowUrl . ')'
        : '未提供';
    $source = $sourceSha !== ''
        ? ($repository !== '' ? '[' . $sourceSha . '](https://github.com/' . $repository . '/commit/' . $sourceSha . ')' : '`' . $sourceSha . '`')
        : '未提供';

    $lines = array(
        '# Sakura ' . $tag,
        '',
        '## 版本标签',
        '',
        '- `' . $tag . '`',
        '- 发布类型：' . $releaseType,
        '',
        '## 发布信息',
        '',
        '- 发布时间：`' . $publishedAt . '`',
        '- 触发方式：' . $trigger,
        '- 工作流运行：' . $workflow,
        '- 源码提交：' . $source,
        '- 变更基线：`' . $previous . '`',
        '',
        '## 支持环境',
        '',
        '- WordPress 最低版本为 7.0，已测试至 7.1。',
        '- PHP 最低版本为 8.0，已测试 8.0、8.1、8.2。',
        '- 同一个主题包适用于上述已验证环境，不需要按 WordPress 或 PHP 版本分别下载。',
        '',
        '## 更新记录',
        '',
        '> 本记录由版本区间 `' . $previous . '..' . $tag . '` 的中文 Git 提交自动生成。',
        '',
        '- 变更文件：' . sakura_release_file_summary($files),
        '- 提交数量：' . count($commits),
        '',
    );

    $hasKnownSection = false;
    foreach (SAKURA_RELEASE_SECTIONS as $type => $title) {
        if (empty($sections[$type])) {
            continue;
        }
        $hasKnownSection = true;
        $lines[] = '### ' . $title;
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
        $hasKnownSection = true;
        $lines[] = '### 其他变更';
        $lines[] = '';
        foreach ($sections['其他'] as $commit) {
            $shortHash = substr($commit['hash'], 0, 7);
            $link = $repository !== '' ? ' [' . $shortHash . '](https://github.com/' . $repository . '/commit/' . $commit['hash'] . ')' : ' `' . $shortHash . '`';
            $lines[] = '- ' . $commit['title'] . $link;
        }
        $lines[] = '';
    }

    if (!$hasKnownSection) {
        $lines[] = '- 本次版本区间内没有可列出的非合并提交。';
        $lines[] = '';
    }

    $compareRange = $previous . '...' . $tag;
    $lines[] = '## 完整更新日志';
    $lines[] = '';
    if ($repository !== '') {
        $compareUrl = 'https://github.com/' . $repository . '/compare/' . rawurlencode($previous) . '...' . rawurlencode($tag);
        $lines[] = '- [查看 `' . $compareRange . '` 的完整变更记录](' . $compareUrl . ')';
    } else {
        $lines[] = '- 比较范围：`' . $compareRange . '`';
    }
    $lines[] = '';

    $lines[] = '## 构建产物';
    $lines[] = '';
    $lines[] = '- `sakura-' . $version . '.zip`：可在 WordPress 后台直接上传的通用主题包。';
    $lines[] = '- `sakura-' . $version . '.zip.sha256`：主题包的 SHA-256 校验文件。';
    $lines[] = '- `release-notes.zh-CN.md`：本页中文更新记录。';
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
    $release = array(
        'version' => '3.5.0',
        'published_at' => '2026-08-23 03:00:00 UTC',
        'trigger' => 'push',
        'workflow_url' => 'https://github.com/example/sakura/actions/runs/123',
        'run_number' => '12',
        'source_sha' => str_repeat('c', 40),
    );
    $notes = sakura_render_release_notes('v3.5.0', 'v3.4.0', $commits, array('functions.php', 'README.md'), 'example/sakura', $release, false);
    if (strpos($notes, '# Sakura v3.5.0') === false
        || strpos($notes, '## 发布信息') === false
        || strpos($notes, '## 支持环境') === false
        || strpos($notes, '## 更新记录') === false
        || strpos($notes, '### 兼容性更新') === false
        || strpos($notes, '修复标题 API') === false
        || strpos($notes, '构建与文档（1 个文件）') === false
        || strpos($notes, '## 完整更新日志') === false
        || strpos($notes, 'https://github.com/example/sakura/compare/v3.4.0...v3.5.0') === false
        || strpos($notes, '`sakura-3.5.0.zip`') === false) {
        fwrite(STDERR, "Release 说明自测失败。\n");
        return 1;
    }
    $emptyNotes = sakura_render_release_notes('v3.5.1-beta.1', 'v3.5.0', array(), array(), '', array('version' => '3.5.1-beta.1'), true);
    if (strpos($emptyNotes, '提交数量：0') === false
        || strpos($emptyNotes, '未检测到文件差异') === false
        || strpos($emptyNotes, '没有可列出的非合并提交') === false
        || strpos($emptyNotes, '比较范围：`v3.5.0...v3.5.1-beta.1`') === false
        || strpos($emptyNotes, 'https://github.com//compare/') !== false
        || strpos($emptyNotes, '此版本为测试版') === false) {
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
$version = sakura_release_argument('version', strpos($tag, 'v') === 0 ? substr($tag, 1) : $tag);
$release = array(
    'version' => $version,
    'published_at' => sakura_release_argument('published-at'),
    'trigger' => sakura_release_argument('trigger'),
    'workflow_url' => sakura_release_argument('workflow-url'),
    'run_number' => sakura_release_argument('run-number'),
    'source_sha' => sakura_release_argument('source-sha'),
);
$prerelease = in_array('--prerelease', $argv, true);

if ($tag === '' || $previous === '' || $output === '') {
    fwrite(STDERR, "用法：php scripts/generate-release-notes.php --tag=vX.Y.Z --previous-tag=<引用> [--output=文件] [--repository=所有者/仓库]\n");
    exit(2);
}

try {
    $commits = sakura_release_commits($previous . '..' . $tag);
    $files = sakura_release_files($previous, $tag);
    $notes = sakura_render_release_notes($tag, $previous, $commits, $files, $repository, $release, $prerelease);
    if (file_put_contents($output, $notes) === false) {
        throw new RuntimeException('无法写入 Release 说明：' . $output);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(2);
}

echo "已生成中文 Release 说明：{$output}\n";

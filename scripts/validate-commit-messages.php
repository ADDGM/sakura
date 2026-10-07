<?php
/**
 * 本地与 CI 共用的英文类型、中文摘要提交校验。
 */

declare(strict_types=1);

const SAKURA_COMMIT_TYPES = array(
    'feat', 'fix', 'docs', 'style', 'refactor', 'perf',
    'test', 'build', 'ci', 'chore', 'revert', 'init',
);

// 仅用于历史发布说明解析，不用于新提交校验。
const SAKURA_LEGACY_COMMIT_TYPES = array(
    '新增',
    '修复',
    '兼容',
    '优化',
    '重构',
    '文档',
    '构建',
    '测试',
    '发布',
);

function sakura_split_git_lines(string $output): array
{
    if ($output === '') {
        return array();
    }
    return preg_split('/\r\n|\r|\n/', trim($output)) ?: array();
}

function sakura_validate_git_ref(string $ref): string
{
    $ref = trim($ref);
    if ($ref === '' || $ref[0] === '-' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/~^-]*$/', $ref)) {
        throw new InvalidArgumentException('Git 引用格式无效：' . $ref);
    }
    return $ref;
}

function sakura_validate_git_range(string $range): string
{
    $parts = explode('..', trim($range), 2);
    if (count($parts) !== 2) {
        throw new InvalidArgumentException('Git 提交范围格式无效：' . $range);
    }
    return sakura_validate_git_ref($parts[0]) . '..' . sakura_validate_git_ref($parts[1]);
}

function sakura_run_git_command(array $arguments): string
{
    if (($arguments[0] ?? '') !== 'git') {
        throw new InvalidArgumentException('只允许执行 Git 命令。');
    }
    $descriptors = array(
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $process = proc_open($arguments, $descriptors, $pipes); // nosemgrep: php.lang.security.exec-use.exec-use
    if (!is_resource($process)) {
        throw new RuntimeException('无法启动 Git 命令。');
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $message = trim($error !== '' ? $error : $output);
        throw new RuntimeException('Git 命令执行失败：' . $message);
    }
    return $output;
}

function sakura_parse_commit_title(string $title, bool $allowLegacy = true): ?array
{
    $types = implode('|', SAKURA_COMMIT_TYPES);
    $pattern = '/^(' . $types . ')(?:\(([^\s():!]+)\))?(!)?: ([^\r\n]+)$/uD';
    $legacy = false;
    if (!preg_match($pattern, $title, $matches)) {
        $pattern = '/^(' . implode('|', SAKURA_LEGACY_COMMIT_TYPES) . ')(?:\(([^)\r\n]+)\))?[：:][ \t]*([^\r\n]+)$/uD';
        if (!$allowLegacy || !preg_match($pattern, $title, $matches)) {
            return null;
        }
        $legacy = true;
    }

    $summary = trim($matches[$legacy ? 3 : 4]);
    if ($summary === '' || !preg_match('/[\x{4e00}-\x{9fff}]/u', $summary)) {
        return null;
    }

    return array(
        'type' => $matches[1],
        'scope' => isset($matches[2]) ? trim($matches[2]) : '',
        'summary' => $summary,
        'breaking' => !$legacy && ($matches[3] ?? '') === '!',
    );
}

function sakura_commit_lines(string $range): array
{
    $range = sakura_validate_git_range($range);
    $baselinePath = dirname(__DIR__) . '/.github/commit-legacy-base.txt';
    $baseline = is_file($baselinePath) ? trim((string) file_get_contents($baselinePath)) : '';
    if (!preg_match('/^[a-f0-9]{40}$/D', $baseline)) {
        throw new RuntimeException('提交规范迁移基线缺失或无效。');
    }
    // CI 使用完整历史；对象缺失必须失败，不静默跳过校验。
    sakura_run_git_command(array('git', 'cat-file', '-e', $baseline . '^{commit}'));
    $output = sakura_run_git_command(array('git', 'log', '--no-merges', '--format=%H%x09%s', $range, '--not', $baseline));

    $commits = array();
    foreach (sakura_split_git_lines($output) as $line) {
        if ($line === '') {
            continue;
        }
        $parts = explode("\t", $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $commits[] = array('hash' => $parts[0], 'title' => $parts[1]);
    }
    return $commits;
}

function sakura_validate_commits(array $commits): array
{
    $errors = array();
    foreach ($commits as $commit) {
        if (sakura_parse_commit_title($commit['title'], false) === null) {
            $errors[] = $commit['hash'] . ' ' . $commit['title'];
        }
    }
    return $errors;
}

function sakura_validate_self_test(): int
{
    $valid = array(
        'fix(WordPress): 修复旧版标题 API',
        'build(工作流): 推送 develop 时生成测试主题包',
        'docs: 更新升级说明',
        'feat(api)!: 调整响应结构',
        'refactor!: 调整模块接口',
    );
    $invalid = array(
        'fix: update workflow',
        '修复: fix workflow',
        '未知(范围): 这是不允许的类型',
        '兼容(PHP):',
        '优化: 更新设置',
        'improve: 更新设置',
        'Feat: 更新设置',
        'fix(): 修复错误',
        'fix( ): 修复错误',
        'fix:修复错误',
        'fix： 修复错误',
        "fix: 修复错误\n另一行",
    );

    foreach ($valid as $title) {
        if (sakura_parse_commit_title($title, false) === null) {
            fwrite(STDERR, "自测失败：合法提交未通过：{$title}\n");
            return 1;
        }
    }
    foreach ($invalid as $title) {
        if (sakura_parse_commit_title($title, false) !== null) {
            fwrite(STDERR, "自测失败：非法提交通过：{$title}\n");
            return 1;
        }
    }

    foreach (SAKURA_COMMIT_TYPES as $type) {
        if (sakura_parse_commit_title($type . ': 校验类型', false) === null) {
            return 1;
        }
    }
    if (sakura_parse_commit_title('兼容(WordPress): 修复标题 API') === null
        || !sakura_parse_commit_title('feat!: 更新接口', false)['breaking']) {
        return 1;
    }
    $lines = sakura_split_git_lines("a\t文档(维护): 补充兼容性说明\r\nb\t兼容(主题): 支持新版 WordPress\n");
    if (count($lines) !== 2 || strpos($lines[0], '补充兼容性说明') === false || strpos($lines[1], '支持新版 WordPress') === false) {
        fwrite(STDERR, "自测失败：中文 Git 输出被错误分行。\n");
        return 1;
    }

    echo "提交规范自测通过。\n";
    return 0;
}

function sakura_validate_cli(array $arguments): int
{
    if (in_array('--self-test', $arguments, true)) {
        return sakura_validate_self_test();
    }

    $inputs = array();
    foreach ($arguments as $argument) {
        if (!preg_match('/^--(range|title|message-file)=(.*)$/s', $argument, $match)
            || isset($inputs[$match[1]])) {
            fwrite(STDERR, "参数无效或重复。\n");
            return 2;
        }
        $inputs[$match[1]] = $match[2];
    }
    if (!$inputs && getenv('COMMIT_RANGE')) {
        $inputs['range'] = getenv('COMMIT_RANGE');
    }
    if (count($inputs) !== 1) {
        fwrite(STDERR, "用法：--title=<标题> 或 --message-file=<文件> 或 --range=<起点..终点>\n");
        return 2;
    }

    try {
        if (isset($inputs['range'])) {
            $commits = sakura_commit_lines($inputs['range']);
        } else {
            $title = $inputs['title'] ?? '';
            if (isset($inputs['message-file'])) {
                $file = $inputs['message-file'];
                if (!is_file($file) || !is_readable($file)) {
                    throw new RuntimeException('提交消息文件不可读。');
                }
                $message = file_get_contents($file);
                if ($message === false) {
                    throw new RuntimeException('提交消息文件读取失败。');
                }
                $title = preg_split('/\r\n|\r|\n/', $message)[0];
            }
            $commits = array(array('hash' => '候选标题', 'title' => $title));
        }
        $errors = sakura_validate_commits($commits);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        return 2;
    }

    if ($errors) {
        fwrite(STDERR, "发现不符合中文提交规范的提交：\n");
        foreach ($errors as $error) {
            fwrite(STDERR, "- {$error}\n");
        }
        fwrite(STDERR, '提交格式：type(scope)!: 中文摘要；范围和 ! 可选；类型为 ' . implode(', ', SAKURA_COMMIT_TYPES) . "。\n");
        return 1;
    }

    echo '已检查 ' . count($commits) . " 个提交，全部符合中文提交规范。\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(sakura_validate_cli(array_slice($argv, 1)));
}

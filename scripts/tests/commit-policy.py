"""提交策略集成检查：只在自动清理的临时 Git 仓库创建测试提交。"""
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[2]
PHP = sys.argv[1] if len(sys.argv) > 1 else "php"
ENV = dict(os.environ, GIT_AUTHOR_NAME="Policy Test", GIT_AUTHOR_EMAIL="test@example.invalid",
           GIT_COMMITTER_NAME="Policy Test", GIT_COMMITTER_EMAIL="test@example.invalid",
           GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_NOSYSTEM="1")
count = 0


def run(args, cwd, expected=0):
    global count
    result = subprocess.run(args, cwd=cwd, env=ENV, capture_output=True, text=True, encoding="utf-8")
    if result.returncode != expected:
        raise AssertionError(f"{args[0]} returned {result.returncode}, expected {expected}: {result.stderr}")
    count += 1
    return result.stdout.strip()


with tempfile.TemporaryDirectory(prefix="sakura-commit-policy-") as directory:
    repo = Path(directory)
    run(["git", "init", "-q"], repo)
    # 环境变量隔离用户的全局Git配置。
    run(["git", "-c", "core.hooksPath=.git/hooks", "commit", "--allow-empty", "-m", "文档: 历史提交"], repo)
    baseline = run(["git", "rev-parse", "HEAD"], repo)
    (repo / "scripts").mkdir()
    (repo / ".github").mkdir()
    for name in ("validate-commit-messages.php", "install-commit-hook.php"):
        shutil.copyfile(ROOT / "scripts" / name, repo / "scripts" / name)
    (repo / ".github/commit-legacy-base.txt").write_text(baseline + "\n", encoding="utf-8")
    validator = [PHP, "scripts/validate-commit-messages.php"]
    run(validator + ["--self-test"], repo)
    run(validator + ["--range=HEAD^..HEAD"], repo, 2)  # 初始提交没有父提交。
    message = repo / "message with spaces.txt"
    for data, status in ((b"", 1), ("fix: 修复问题\r\n\r\n正文\r\n".encode(), 0),
                         ("改进: 更新设置\n".encode(), 1), ("fix: English only\n".encode(), 1)):
        message.write_bytes(data)
        run(validator + ["--message-file=" + str(message)], repo, status)
    run(validator + ["--message-file=missing.txt"], repo, 2)
    run(validator + ["--title=feat!: 更新接口", "--range=HEAD..HEAD"], repo, 2)
    pre = repo / ".git/hooks/pre-commit"
    pre.write_text("#!/bin/sh\nexit 0\n", encoding="utf-8")
    pre.chmod(0o755)
    run([PHP, "scripts/install-commit-hook.php"], repo)
    hook = repo / ".git/hooks/commit-msg"
    original = hook.read_bytes()
    run([PHP, "scripts/install-commit-hook.php"], repo)
    assert hook.read_bytes() == original and pre.read_text() == "#!/bin/sh\nexit 0\n"
    run(["git", "commit", "--allow-empty", "-m", "feat(设置): 新增标签"], repo)
    head = run(["git", "rev-parse", "HEAD"], repo)
    run(["git", "commit", "--allow-empty", "-m", "改进: 不允许的类型"], repo, 1)
    assert run(["git", "rev-parse", "HEAD"], repo) == head
    run(validator + [f"--range={baseline}..HEAD"], repo)
    run(validator + [f"--range={baseline}..{baseline}"], repo)
    basefile = repo / ".github/commit-legacy-base.txt"
    basefile.write_text("0" * 40, encoding="utf-8")
    run(validator + [f"--range={baseline}..HEAD"], repo, 2)
    basefile.write_text(baseline, encoding="utf-8")
    # 仅在临时仓库绕过钩子制造CI负例，证明新中文提交仍被范围校验拒绝。
    empty_hooks = repo / "empty-hooks"
    empty_hooks.mkdir()
    run(["git", "-c", f"core.hooksPath={empty_hooks}", "commit", "--allow-empty", "-m", "文档: 新的旧式标题"], repo)
    run(validator + [f"--range={baseline}..HEAD"], repo, 1)
    hook.write_text("#!/bin/sh\n# another tool\nexit 0\n", encoding="utf-8")
    other = hook.read_bytes()
    run([PHP, "scripts/install-commit-hook.php"], repo, 1)
    assert hook.read_bytes() == other
    run(["git", "config", "core.hooksPath", "custom-hooks"], repo)
    run([PHP, "scripts/install-commit-hook.php"], repo, 1)
    run(["git", "config", "core.hooksPath", ""], repo)
    run([PHP, "scripts/install-commit-hook.php"], repo, 1)

print(f"PASS: {count} command checks; temporary repository removed")

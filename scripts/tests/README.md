# 发布脚本自测

在已安装 PHP 8.0 或更高版本的环境中执行：

```bash
php scripts/validate-commit-messages.php --self-test
php scripts/generate-release-notes.php --self-test
```

CI 会在 PHP 矩阵中执行这两项自测。

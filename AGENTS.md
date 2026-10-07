# Sakura 仓库工作约定

## 提交规范

- 新提交使用英文类型和中文摘要：`type(scope)!: 中文摘要`，scope与!可选。类型及用途以 [README](README.md) 和 [校验器](scripts/validate-commit-messages.php) 为准：feat、fix、docs、style、refactor、perf、test、build、ci、chore、revert、init。
- 提交前必须运行 `php scripts/validate-commit-messages.php --title="候选标题"`，通过后再按用户授权提交。不得只参考历史中文标题猜类型，不新增“改进”等同义词；perf仅用于性能，构建依赖归build，流水线归ci。
- 使用 `php scripts/install-commit-hook.php` 安装commit-msg；保留其他钩子，不为通过检查使用--no-verify。PHP不在PATH时使用实际可用的完整路径。
- 迁移边界以前的中文历史只供兼容解析，不重写历史。CI与本地使用同一校验脚本；遇到检查不可用或失败，先解决并记录原因。

## 维护笔记与本地记录

经内容审核的维护笔记由 Git 共享，范围和阅读方式见 [.agents/README.md](.agents/README.md)。改动相关模块前先阅读对应笔记；行为、架构、跨文件规则或验证策略变化时，同步其理由、代价和验证范围。

- `.agents/` 采用目录内的 [.gitignore](.agents/.gitignore) 按用途划分范围：`notes/` 整棵树可纳入 Git，包含 proposed、implemented、rejected、archived；私人想法、工具和个人流程记录放在被忽略的 `local/`。新增项目笔记或迁移生命周期无需修改忽略规则，提交前仍需审核内容和引用。
- 共享笔记应能由仓库读者独立理解；链接只依赖随 Git 分发的文件或公共来源，保留历史验证的版本与限制，不要求协作者取得私人计划、截图或原始日志。
- 当前工作副本若存在 `.agents/local/workflow.md`，使用本地记录体系的 Agent 应读取其补充约定；`.projectmem/`、`.agents/local/`、`.agents/legacy/` 和 `.agents/validation/` 继续忽略。缺少这些本地记录不阻塞开发、评审或验证，也不要求安装维护者的个人工具。
- 简单检查结果写入笔记即可；协作需要的证据写入 PR 或经审核的共享文档。开发笔记通过 `.gitattributes` 从主题安装包排除。

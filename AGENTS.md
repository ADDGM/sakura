# Sakura 仓库工作约定

## 提交规范

- 新提交使用英文类型和中文摘要：`type(scope)!: 中文摘要`，scope与!可选。类型及用途以 [README](README.md) 和 [校验器](scripts/validate-commit-messages.php) 为准：feat、fix、docs、style、refactor、perf、test、build、ci、chore、revert、init。
- 提交前必须运行 `php scripts/validate-commit-messages.php --title="候选标题"`，通过后再按用户授权提交。不得只参考历史中文标题猜类型，不新增“改进”等同义词；perf仅用于性能，构建依赖归build，流水线归ci。
- 使用 `php scripts/install-commit-hook.php` 安装commit-msg；保留其他钩子，不为通过检查使用--no-verify。PHP不在PATH时使用实际可用的完整路径。
- 迁移边界以前的中文历史只供兼容解析，不重写历史。CI与本地使用同一校验脚本；遇到检查不可用或失败，先解决并记录原因。

## 文档与任务记录试行

从 2026-10-07 起，本仓库采用“新任务使用决策笔记，旧资料按需提炼”的方式。具体取舍见[流程决定](.agents/notes/implemented/process/2026-10-07-notes-workflow-trial.md)。

- **任务状态**：非规格任务的待办、完成条件和当前进度只在 [.projectmem/plan.md](.projectmem/plan.md) 维护，链接相关笔记和证据，不复制完整调查过程。正式由 spec-workflow 管理的任务仍以原规格的 `tasks.md` 为准，项目计划只保留入口链接。
- **决策理由**：`.agents/notes/` 记录问题、选择依据、真实考虑过的替代方案、边界、代价与验证结论。改动行为、架构、跨文件契约、流程工具、测试策略或数据格式时，使用 `write-notes-like-deepseek`；已有归属先更新原笔记。纯排版、机械修改等不另立笔记。
- **历史资料**：既有 `.zcf/` 原文和附件保留为只读参考。新任务不创建 `.zcf/plan/current`，完成后也不归档到 `.zcf/plan/history`；这两条覆盖 `dev-workflow` 等技能的默认保存路径。继续旧任务时，在项目计划更新状态，按需提炼仍有价值的决策，并链接原文。
- **验证证据**：简短结果直接写入相关笔记；需要保存截图、日志或 JSON 时，使用 `.agents/validation/<任务名>/`，不放进笔记分类树，也不复制既有 `.zcf/validation` 附件。
- **项目事件**：projectmem 继续记录问题、尝试和结果。新决策事件只记一句摘要及笔记路径，完整理由以笔记为准；`plan.md` 可直接编辑，`summary.md` 和 `events.jsonl` 仍通过 projectmem 工具维护。

开始相关工作前，读取项目计划、projectmem 摘要和对应模块的活跃笔记；资料与当前源码或验证证据冲突时，核对后记录当前结论，不把历史勾选状态当作实现证明。

## 笔记约定

- 路径为 `.agents/notes/{lifecycle}/{class}/yyyy-mm-dd-topic.md`，仅创建实际使用的目录，不创建 `INDEX.md`。
- lifecycle 使用 `proposed`、`implemented`、`rejected`、`archived`；class 使用 `feature`、`bug-fix`、`simplification`、`architecture`、`process`、`testing`。
- 有具体方案但尚未实施时用 `proposed`；落地后转为 `implemented`，同步现在时决策与验证边界。仍约束现行实现的决定留在活跃区。
- 新笔记先检索相关活跃笔记并记录重叠处理。决定或理由翻转时新建并互链，不覆盖旧理由；不得补造旧资料未记载的备选方案或首次提出日期。
- 旧 ZCF 的 `history` 不等于 Notes 的 `archived`。只有已实施且未来参考价值较低的笔记才按技能脚本封存；封存后永久冻结。

试行期间 `.agents/` 与 `.projectmem/` 沿用现有 Git 忽略规则，只在本地保存；本次不改变全局技能、CI 或主题打包配置。后续若需要共享笔记，再明确版本控制和安装包排除规则。

## 本地校验

在仓库根目录使用已安装的技能校验器。当前 Node.js 22.23.1 可以直接运行以下 TypeScript 脚本，无需新增项目依赖：

```powershell
node "$env:USERPROFILE/.codex/skills/write-notes-like-deepseek/scripts/verify-agent-note-tree.ts"
node "$env:USERPROFILE/.codex/skills/write-notes-like-deepseek/scripts/verify-agent-note-format.ts"
```

试行笔记被 Git 忽略，检索时显式指定目录，例如：

```powershell
rg --hidden --glob '!.agents/notes/archived/**' "<关键词>" ".agents/notes"
```

旧任务不为统一格式而批量转换；完成 2～3 个后续非平凡任务后，在项目计划的复盘项评估是否继续推广。

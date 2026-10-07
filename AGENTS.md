# Sakura 仓库工作约定

## 提交规范

- 新提交使用英文类型和中文摘要：`type(scope)!: 中文摘要`，scope与!可选。类型及用途以 [README](README.md) 和 [校验器](scripts/validate-commit-messages.php) 为准：feat、fix、docs、style、refactor、perf、test、build、ci、chore、revert、init。
- 提交前必须运行 `php scripts/validate-commit-messages.php --title="候选标题"`，通过后再按用户授权提交。不得只参考历史中文标题猜类型，不新增“改进”等同义词；perf仅用于性能，构建依赖归build，流水线归ci。
- 使用 `php scripts/install-commit-hook.php` 安装commit-msg；保留其他钩子，不为通过检查使用--no-verify。PHP不在PATH时使用实际可用的完整路径。
- 迁移边界以前的中文历史只供兼容解析，不重写历史。CI与本地使用同一校验脚本；遇到检查不可用或失败，先解决并记录原因。

## 维护笔记与本地记录

经内容审核的维护笔记由 Git 共享，范围和阅读方式见 [.agents/README.md](.agents/README.md)。改动相关模块前先阅读对应笔记。

- `.agents/` 采用目录内的 [.gitignore](.agents/.gitignore) 按用途划分范围：`notes/` 整棵树可纳入 Git，包含 proposed、implemented、rejected、archived；私人想法、工具和个人流程记录放在被忽略的 `local/`。新增项目笔记或迁移生命周期无需修改忽略规则，提交前仍需审核内容和引用。
- 共享笔记应能由仓库读者独立理解；链接只依赖随 Git 分发的文件或公共来源，保留历史验证的版本与限制，不要求协作者取得私人计划、截图或原始日志。
- 当前工作副本若存在 `.agents/local/workflow.md`，使用本地记录体系的 Agent 应读取其补充约定；`.projectmem/`、`.agents/local/`、`.agents/legacy/` 和 `.agents/validation/` 继续忽略。缺少这些本地记录不阻塞开发、评审或验证，也不要求安装维护者的个人工具。
- 简单检查结果写入笔记即可；协作需要的证据写入 PR 或经审核的共享文档。开发笔记通过 `.gitattributes` 从主题安装包排除。

## 重要改动必须留笔记

1. 非平凡改动（行为、架构、跨文件契约、流程与工具链、测试策略、落盘/网络/配置格式）前，按本仓库笔记约定写或更新笔记。已安装 `write-notes-like-deepseek` 的 Agent 使用实际安装位置的技能说明；纯排版、格式化等机械修改不另立笔记，提交仍遵循授权与校验要求。
2. 写之前先检索 `.agents/notes/` 中的同主题活跃笔记：同一决定的事实变化就地更新；成形提案先放 `proposed/`，落地后随代码转为 `implemented/`。决定或理由翻转时另立笔记并互链，保留有用的旧理由；仅在符合归档条件时封存旧篇，仍约束现行实现的决定不归档。
3. 只记录真实考虑过的备选方案，先写其最强理由，再解释取舍；同步代价、验证结果与未覆盖的范围。
4. 提交笔记改动前，检查目录、状态、必填章节和相对链接；涉及归档时检查冻结完整性。已安装技能的环境从仓库根目录用 Node.js 运行技能目录下的 `scripts/verify-agent-note-tree.ts`、`scripts/verify-agent-note-format.ts`，归档检查使用 `scripts/verify-archived-agent-notes.ts`。按实际安装位置定位脚本；未安装技能的协作者依据仓库笔记约定审核，不将维护者的个人工具作为开发前置条件。

## 决策看板

- 优先使用项目级 `.agents/skills/write-notes-like-deepseek/`，缺失时查找当前 Agent 的用户级安装；均不可用时说明看板未更新，不自动安装依赖。命令及用途见 [.agents/README.md](.agents/README.md#决策看板)。
- 一轮共享笔记的新建、修改或生命周期迁移完成后，Agent 在任务收尾通过笔记校验，再用 `--bundle` 刷新 `.agents/notes/board.html`，标题为“项目决策看板”。分发前同样核对并刷新；无相关变化时不重复生成。输入仅限 `.agents/notes/`，不混入私人笔记、历史资料或验证附件。
- 根目录 `board.html` 使用 `--init`，在缺失、技能看板模板更新或现有文件仍为打包版时生成；日常笔记变化由浏览器连接目录后直接读取。该文件仅本地保存；分发版可随共享笔记审核提交，两者均排除于主题安装包。
- 自动生成由 Agent 在上述任务节点执行，不安装定时任务、文件监听、Git 钩子或 CI。覆盖前确认目标是技能生成的看板；先临时生成并校验，内容变化时再替换，非看板文件或生成失败时保留旧文件并说明未更新。生成不代表获准提交、推送或发布。

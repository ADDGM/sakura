# Sakura 维护笔记

这里共享会影响后续维护的设计理由、兼容约束、备选方案及验证结论。主题源码说明实现，笔记补充容易丢失的意图和限制；它们不属于 WordPress 运行资源。

## 共享范围

`notes/` 整棵目录用于共享项目决策，当前包含17篇维护笔记；共享说明和目录内的[.gitignore](.gitignore)也随Git分发。按用途分开存放后，新增笔记或调整生命周期不再需要维护逐文件白名单：

| 分类 | 内容 |
| --- | --- |
| architecture | 核心资源、Live2D接入、播放器缓存、后台设置 |
| bug-fix | 评论表单初始化、Live2D与播放器布局 |
| feature | 看板娘设置、原生主题更新、导航断点、建站日期、测试邮件 |
| process | 提交规范、CI通知、质量检查、正式发布 |
| testing | 兼容性验证、Live2D部署边界 |

按模块在 `notes/implemented/` 检索现行决定，在 `notes/proposed/` 检索待评审提案；目录按需创建。当前源码或新证据与笔记冲突时先核实，再同步结论。历史结果标注日期和版本，不代表当前代码已经重新执行同一轮测试。

## 编辑与审核

- 优先更新负责该决定的原笔记；决定或理由翻转时另立笔记并说明取代关系，保留有用的旧理由。纯排版或机械修改无需新增笔记。
- 路径采用 `notes/{lifecycle}/{class}/yyyy-mm-dd-topic.md`。lifecycle使用proposed、implemented、rejected、archived；class使用feature、bug-fix、simplification、architecture、process、testing。只创建用到的目录。
- `proposed`不等于私人草稿：已经形成问题、方案、取舍并准备协作评审的项目提案放入共享 `notes/proposed/`；尚未整理的个人想法放在本地 `local/`。共享取决于内容和用途，不要求先变成implemented。
- 保留问题、选择、真实考虑过的替代方案、代价和验证范围；没有记录的历史选型或测试结果不得补造。日期与历史事件不同的，应说明成文时间。
- `notes/` 下所有生命周期都可纳入 Git，新增或移动笔记无需改忽略规则。放入该目录前确认内容适合协作，提交前审核差异及引用；可跟踪不等于已经提交，不使用 `git add -f` 绕过本地目录边界。
- 共享链接必须指向仓库文件或公共来源。不得以私人计划、未共享附件或本机绝对路径作为读者必需入口；凭据、后台会话和个人环境记录不进入共享笔记。

## 决策看板

| 文件 | 用途 | 更新方式 |
| --- | --- | --- |
| 根目录 `board.html` | 本地直读，首次打开后连接 `.agents/notes/`；不内嵌笔记 | 缺失、模板更新或需从打包版切换时使用 `--init`；Git 忽略 |
| [notes/board.html](notes/board.html) | 内嵌共享笔记全文的“项目决策看板”，可作为单个 HTML 分发 | 一轮共享笔记变更完成并通过校验后由 Agent 刷新，分发前再次核对；可随笔记纳入 Git |

在已安装项目级技能的工作副本中，从仓库根目录运行：

```powershell
node ".agents/skills/write-notes-like-deepseek/scripts/build-board.ts" --init "board.html" "项目决策看板"
node ".agents/skills/write-notes-like-deepseek/scripts/build-board.ts" --bundle ".agents/notes" ".agents/notes/board.html" "项目决策看板"
```

项目级技能不存在时，替换为当前 Agent 的实际用户级安装位置；均未安装时保留现有看板并说明未更新，不要求协作者安装个人工具。生成由 Agent 在任务节点执行，不设置后台监听或自动发布。上述命令展示目标路径；刷新已有看板时，先输出到目标旁的临时 HTML，校验成功后再替换，内容相同则保留原文件，并清理临时文件。只覆盖已确认的生成物；失败保留旧版，源码笔记始终是事实来源，不直接编辑 HTML 中的笔记。

分发版只读取共享笔记，不包含 `local/`、`legacy/` 或 `validation/`。它是生成时的快照，原笔记发生变化后需要重新打包；HTML 本身不参与笔记校验或被打包为笔记。根目录版和分发版都不进入 WordPress 主题安装包。浏览器直接读取本地目录需要用户授权，建议使用支持目录访问的 Chrome 或 Edge；分发版已内嵌正文，无需连接目录。模板的在线字体不可用时使用系统字体。

## 本地资料与发布包

`local/` 保存私人想法、个人工具和流程记录；`local/workflow.md` 是可选的本地工作约定，`local/notes/` 保存结构化私人笔记。`legacy/`、`validation/` 和仓库根目录的 `.projectmem/` 也继续仅本地保存。除共享入口和 `notes/` 外，`.agents/` 的其他直接子项默认忽略，不能把共享项目笔记与私人记录混放在 `notes/`。

这些本地资料不随克隆提供，缺失属于正常情况。部分必要附件只在维护者本地保管，已经归并结论的一次性报告和附件可能已退役；共享笔记保留对应摘要和未覆盖的范围，不保证历史原附件仍可取得。需要新的验证时按当前代码和环境执行。

必要检查入口在仓库的 `scripts/`、`scripts/tests/` 和 GitHub Actions 中；编辑笔记不要求安装维护者个人工具。本次共享不新增CI门禁或第三方依赖。`.gitattributes` 将整个 `.agents` 目录排除于 `git archive`，CI及正式Release生成的可安装主题包不包含开发笔记。

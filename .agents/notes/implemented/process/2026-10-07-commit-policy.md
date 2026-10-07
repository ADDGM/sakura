# Agent Note: 提交类型与本地及CI校验统一

Status: implemented

## Problem

提交e8ea664使用了仓库不允许的中文类型“改进”，本地缺少commit-msg钩子，直到CI才失败。提交格式需要由同一校验器在本地与CI执行，避免规则仅靠说明文字传达。

## Decision

使用feat、fix、docs、style、refactor、perf、test、build、ci、chore、revert和可选扩展init。范围可选、摘要必须含中文，英文冒号后有空格，支持!。perf仅用于性能，依赖/构建归build，流水线归ci，chore为其他维护。

校验脚本提供title、message-file和range入口。范围入口排除固定迁移边界66429c1及其祖先，提交消息入口始终执行新规范；发布说明保留历史中文解析并增加英文分组。不重写历史，不放宽新提交。

新增可重复运行且拒绝覆盖非本工具钩子的安装器，仅安装commit-msg，不改core.hooksPath和其他已有钩子。提交前使用[scripts/validate-commit-messages.php](../../../../scripts/validate-commit-messages.php)检查候选标题；使用[scripts/install-commit-hook.php](../../../../scripts/install-commit-hook.php)安装仓库钩子。

后续提炼的[兼容性验证](../testing/2026-10-07-compatibility-evidence.md)与本篇部分重叠；旧兼容性总计划中的中文提交类型仅是历史背景，新提交继续以本篇已落地规范为准。

## Alternatives considered

- 永久允许中英文类型：迁移容易，但本地规则继续含糊；只允许发布解析兼容历史。
- 重写旧中文提交：历史整齐，但破坏协作且无必要；采用固定祖先边界。
- 用core.hooksPath替换全部钩子：便于版本控制，但可能绕过其他已有钩子；选择单个安全安装器。

## Existing record audit

[看板娘标签](../../implemented/feature/2026-10-07-live2d-settings-tab.md)包含本次失败的历史证据，部分重叠，仅链接不覆盖旧事实。

## Verification

27项隔离命令检查通过：新类型正反例、中文摘要、范围及!、消息文件CRLF/正文/空文件、历史边界和缺失对象、合法提交成功/非法提交不产生新SHA、已有钩子拒绝覆盖、重复安装一致、空/非空hooksPath拒绝。临时仓库自动清理。

仓库实际commit-msg通过git hook run验证合法标题exit0、非法类型exit1；原pre-commit/post-commit/post-merge哈希不变。安装检查原件仅本地保存。首次隔离测试暴露空hooksPath误判，修正后通过。

PHP语法、历史和英文混合Release分组及破坏性标记自测、50条PHP Semgrep规则扫描（三文件0发现）、git diff --check通过。CI YAML已解析并接入集成测试；6b10d85已获授权提交推送，兼容性37573963024（构建78）与质量37573963055全部成功，新提交规范和钩子迁移集成步骤通过。公开来源见[兼容性运行](https://github.com/ADDGM/sakura/actions/runs/37573963024)和[质量运行](https://github.com/ADDGM/sakura/actions/runs/37573963055)；本段为历史验收摘要。

## Consequences

当前工作流的Telegram汇总属于后续补充，依赖与展示规则见[通知汇总](2026-10-07-ci-notification-summary.md)，提交规范不因此改变。

新提交在本地被拦截，CI与本地共享规则，发布说明仍能识别历史中文。代价是每次克隆需安装钩子；本地钩子依赖安装时PHP路径，解释器移动后需重新接入，缺失时拒绝提交。固定边界要求完整历史，缺失对象报错，不能仅靠浅克隆完成范围检查。CI略过合并提交，手动合并/回退及临时fixup提交需按README提供合规标题。Git只分发安装器，不分发已安装的本地钩子；克隆后需在本机安装。

# Agent Note: Telegram 汇总当前兼容性工作流

Status: implemented

## Problem

Telegram通知原先只依赖build，提交检查或WordPress烟雾测试失败时仍可能报告构建成功。用户要求汇总当前工作流，避免将打包成功误解为整轮CI通过。

## Decision

notify依赖build、commit-message和wordpress-smoke，保留always()；消息分别展示三组结果。整体状态按失败、取消、跳过、全部成功、未知的顺序判断。仅三组均success时显示CI全部通过。

消息明确范围为当前兼容性工作流，不包括独立质量工作流。保留通知错误不阻塞CI，以及未配置凭据时跳过并写入job summary的行为。

## Existing record audit

[提交策略](2026-10-07-commit-policy.md)涉及同一工作流的提交检查，部分重叠；本笔记只负责通知依赖及状态呈现，不改变提交规则。既有活跃笔记未定义Telegram汇总策略。

后续提炼的[质量工作流](2026-10-07-quality-workflow-boundary.md)与本篇部分重叠，明确独立job、扫描发现及SARIF结果边界；质量流程仍不纳入当前通知汇总。

## Alternatives considered

- 保留只通知build：能更早获得打包结果，但不能体现当前工作流的验收结论。
- 合并独立质量工作流：信息更全面，但需要跨工作流协调和关联同一提交；超出用户本轮要求。

## Consequences

通知更晚发出，但状态与当前工作流三组检查一致。各矩阵显示组级结果，具体失败组合仍通过工作流链接查看。通知发送失败继续不影响测试结果。

## Verification

YAML结构、三项needs、always()和continue-on-error均已检查。读取实际消息表达式并在本地等价求值，success/failure/cancelled/skipped的64种组合全部符合优先级；原检查JSON仅本地保存。git diff --check通过。

6b10d85获授权提交推送后，构建78（37573963024）整体success。Telegram任务在全部前置任务完成后启动，Send Telegram notification步骤success，未配置跳过步骤为skipped；时序及步骤可按[构建78运行](https://github.com/ADDGM/sakura/actions/runs/37573963024)追溯。这证明发送步骤正常执行，不等于确认用户已阅读；失败/取消/跳过组合仍为本地验证，未故意制造远端失败或额外测试消息。

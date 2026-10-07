# Agent Note: 独立质量工作流与扫描结果边界

Status: implemented

## Problem

工作流语法和安全扫描分散运行增加Actions列表条目，但合并时不能混淆检查权限、结果或重跑范围。扫描流程成功也容易被误读为没有安全告警。

## Decision

[quality.yml](../../../../.github/workflows/quality.yml)聚合 actionlint 与 semgrep 两个独立并行job；没有彼此的needs依赖，仍与兼容性ci.yml分开。actionlint使用contents:read，Semgrep额外具有security-events:write以上传SARIF。

当前job显示名是“检查 GitHub Actions 工作流”和“Semgrep 安全扫描”，后者与旧计划的“Semgrep 扫描”不同；本轮只记录差异，没有检查或更改远端分支保护。actionlint下载校验SHA-256，Semgrep容器固定版本，Action仍采用版本标签。

[.semgrepignore](../../../../.semgrepignore)当前精确排除四个vendor源文件：jquery、APlayer、hls和iris。旧计划曾列入highlight.js，当前排除文件已不含它；不得复制旧五文件名单或用宽泛目录替代。vendor升级和应用代码修复分开评估。

Semgrep命令为 scan --config auto，工作流没有额外的“发现即失败”参数；SARIF上传还使用continue-on-error。必须分别查看扫描发现、执行失败和上传结果，job成功不等于零告警或上传必然成功。

## Existing record audit

与[通知汇总](2026-10-07-ci-notification-summary.md)部分重叠：通知只汇总兼容性工作流，本篇不扩大其范围。与[Live2D验证边界](../testing/2026-10-07-live2d-deployed-boundaries.md)的单条告警分析部分重叠，不把该分析扩展成全库安全结论。原质量工作流合并与扫描修复的结论已按这些职责归并。

## Alternatives considered

- 保留两个工作流：列表和运行相互独立，但普通推送会增加运行条目；原计划采用一个工作流保留两个job。
- 改用Action提交SHA：固定内容更明确，但原安全计划记录用户选择保留版本标签；本轮不改变该选择，也不把标签称为不可变固定。
- vendor立即整体升级：可一并处理依赖问题，但原记录暂缓并精确排除指定文件；排除不等于已经修复或没有风险。

## Consequences

检查结构集中，权限和失败仍可区分；代价是标签引用和暂缓vendor升级仍需后续维护。历史“剩余15条”只是当时快照，现有Notes已有其他告警记录，不能把它写成当前总数。评估剩余项需要重新读取对应提交的扫描结果，并单独调查旧依赖。

## Verification

2026-10-07直接核对当前YAML、权限、并行结构、扫描命令与精确排除文件。历史34624177433/34683951794及v3.7.0发布证据记录对应版本的质量执行成功；本轮未重跑扫描、查询GitHub告警或修改CI。成文日不推定历史决定最初日期。

## Historical scan interpretation

旧报告的告警计数仅属于对应轮次：2026-09-23本地Semgrep1.176.0列8个目标，1条只读属性遍历提示经该调用路径复核无原型写入，另有1条Windows临时模式权限错误和16条Pro-only错误；2026-09-30版本1.178.0以434规则扫描4文件，2条既有提示，9条Pro-only错误及1处PartialParsing；2026-10-06播放器修复为3文件/370规则，仍有只读遍历提示及9条Pro-only限制。敏感模式未命中和没有新增依赖，都不代表全库秘密或依赖漏洞已清零。

ec55e3f/c7ee80b的Linux日志为289文件/516规则、19条提示，GitHub分析接口为22条结果，两者口径不同；当时告警身份记录无新增，不是完整SARIF指纹比较（下载曾HTTP/2 CANCEL）。到20dd904出现第28号unsafe-formatstring、历史快照23条open，不能延用上一轮“无新增”。该第三方运行时日志辅助函数未因播放器修复改变，仍无充分可控输入证据，不关闭、不认定已修复；具体调用追查由[部署边界笔记](../testing/2026-10-07-live2d-deployed-boundaries.md)保留。原JSON/日志和旧报告清理后，本节保存解释边界，未查询当前远端告警数。

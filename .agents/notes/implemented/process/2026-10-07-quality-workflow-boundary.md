# Agent Note: 独立质量工作流与扫描结果边界

Status: implemented

## Problem

工作流语法和安全扫描分散运行增加Actions列表条目，但合并时不能混淆检查权限、结果或重跑范围。扫描流程成功也容易被误读为没有安全告警。

## Decision

[quality.yml](../../../../.github/workflows/quality.yml)聚合 actionlint 与 semgrep 两个独立并行job；没有彼此的needs依赖，仍与兼容性ci.yml分开。actionlint使用contents:read，Semgrep额外具有security-events:write以上传SARIF。

当前job显示名是“检查 GitHub Actions 工作流”和“Semgrep 安全扫描”，后者与旧计划的“Semgrep 扫描”不同；本轮只记录差异，没有检查或更改远端分支保护。actionlint下载校验SHA-256，Semgrep容器固定版本，Action仍采用版本标签。

[.semgrepignore](../../../../.semgrepignore)当前精确排除三个vendor源文件：jquery、APlayer和hls。2026-10-08移除后台旧Iris副本时同步删除其失效排除项；已有文件的扫描边界没有扩大排除。旧计划曾列入highlight.js，当前排除文件已不含它；不得复制旧名单或用宽泛目录替代。vendor升级和应用代码修复分开评估。

Semgrep命令为 scan --config auto，工作流没有额外的“发现即失败”参数；SARIF上传还使用continue-on-error。必须分别查看扫描发现、执行失败和上传结果，job成功不等于零告警或上传必然成功。

## Existing record audit

与[通知汇总](2026-10-07-ci-notification-summary.md)部分重叠：通知只汇总兼容性工作流，本篇不扩大其范围。与[Live2D验证边界](../testing/2026-10-07-live2d-deployed-boundaries.md)的单条告警分析部分重叠，不把该分析扩展成全库安全结论。原质量工作流合并与扫描修复的结论已按这些职责归并。

## Alternatives considered

- 保留两个工作流：列表和运行相互独立，但普通推送会增加运行条目；原计划采用一个工作流保留两个job。
- 改用Action提交SHA：固定内容更明确，但原安全计划记录用户选择保留版本标签；本轮不改变该选择，也不把标签称为不可变固定。
- vendor立即整体升级：可一并处理依赖问题，但原记录暂缓并精确排除指定文件；排除不等于已经修复或没有风险。

## Consequences

检查结构集中，权限和失败仍可区分；代价是标签引用和暂缓vendor升级仍需后续维护。历史“剩余15条”只是当时快照，现有Notes已有其他告警记录，不能把它写成当前总数。2026-10-08的对应提交复核见下节；后续评估仍需刷新扫描结果，并单独调查旧依赖。

## Verification

2026-10-07直接核对当前YAML、权限、并行结构、扫描命令与精确排除文件。历史34624177433/34683951794及v3.7.0发布证据记录对应版本的质量执行成功；本轮未重跑扫描、查询GitHub告警或修改CI。成文日不推定历史决定最初日期。

## Historical scan interpretation

旧报告的告警计数仅属于对应轮次：2026-09-23本地Semgrep1.176.0列8个目标，1条只读属性遍历提示经该调用路径复核无原型写入，另有1条Windows临时模式权限错误和16条Pro-only错误；2026-09-30版本1.178.0以434规则扫描4文件，2条既有提示，9条Pro-only错误及1处PartialParsing；2026-10-06播放器修复为3文件/370规则，仍有只读遍历提示及9条Pro-only限制。敏感模式未命中和没有新增依赖，都不代表全库秘密或依赖漏洞已清零。

ec55e3f/c7ee80b的Linux日志为289文件/516规则、19条提示，GitHub分析接口为22条结果，两者口径不同；当时告警身份记录无新增，不是完整SARIF指纹比较（下载曾HTTP/2 CANCEL）。到20dd904出现第28号unsafe-formatstring、历史快照23条open，不能延用上一轮“无新增”。该第三方运行时日志辅助函数未因播放器修复改变，仍无充分可控输入证据，不关闭、不认定已修复；具体调用追查由[部署边界笔记](../testing/2026-10-07-live2d-deployed-boundaries.md)保留。原JSON/日志和旧报告清理后，本节保存解释边界，未查询当前远端告警数。

## Scan review 2026-10-08

读取GitHub Code Scanning的open告警、develop最新analysis与该analysis导出的SARIF：analysis `1910095638`对应`c842725235c681584725eefcc959b800fd2f4612`，创建时间为2026-10-08 01:19:14（北京时间），工具为Semgrep OSS 1.176.0，API记录1,074条规则、22条结果，error与warning字段为空。此处是已上传结果的复核，没有对本地未提交改动重新执行全库扫描，也不将API字段为空视为所有分析均完整。

| 告警 | 数量 | 当前调用边界与处理 |
| --- | --- | --- |
| #1–12、#22、#23、#25：Actions可变标签 | 15 | 三个工作流的15处引用与告警位置一致；按既有决定保留版本标签，供应链风险继续存在。没有修改引用或关闭告警。 |
| #19：QQ解密失败处理 | 1 | [QQ.php](../../../../inc/classes/QQ.php)在`openssl_decrypt`后检查`false`并校验纯数字；该行已有精确`nosemgrep`。告警所述“未处理false”与当前代码不符。此结论不评估旧CBC协议的机密性或完整性。 |
| #20：unlink使用 | 1 | [元数据自测](../../../../scripts/theme-metadata.php)仅在finally中清理本次`tempnam`生成的文件，不从请求或CLI路径参数获取删除目标；该行已有精确`nosemgrep`。 |
| #21：命令执行 | 1 | [提交校验器](../../../../scripts/validate-commit-messages.php)限定`git`并将参数数组传给`proc_open`，未拼接shell命令；引用参数另有字符和选项前缀校验。该行已有精确`nosemgrep`；仍依赖本地Git与调用方传入固定子命令的信任边界。 |
| #27：原型污染提示 | 1 | [renderTip](../../../../live2d/js/message.js)将属性值读入局部变量，没有向对象属性或原型写入；两处调用传入文本上下文，插入HTML前转义。当前路径不构成告警描述的原型写入，不据此证明所有动态属性读取都安全。 |
| #16、#18：动态正则 | 2 | [highlight.js 11.12.0](../../../../cdn/js/src/03.highlight.pack.js)按语言定义构造正则，页面正文作为待匹配文本；[主题调用](../../../../js/sakura-app.js)使用`highlightElement`。当前调用未发现将正文作为正则模式的路径；未覆盖所有语言或对抗性长输入，不能认定全部ReDoS风险消失。 |
| #17：日志格式字符串 | 1 | 同一highlight.js文件的`K`有两处显式调用：动态语言提示仅传一个参数，携带DOM对象的回退提示使用固定格式文本。本轮未发现攻击者同时控制格式文本与替换参数的调用；未修改第三方代码。 |

GitHub导出的SARIF中，#19、#20、#21各带`suppressions: [{"state":"accepted"}]`，但告警API仍将三者列为open。因此22条open包含3条已有抑制记录的结果，不能直接等同于22个待修漏洞；也不能仅凭抑制记录宣称远端告警已经关闭。本轮没有改抑制规则、上传新的SARIF或变更GitHub告警状态。

针对性验证使用PHP 8.2.33与Node.js：提交规范、元数据脚本两项现有自测通过；5个QQ样例覆盖非法base64、无效密文、非数字、过短值与正常数字，5个含选项或shell元字符的Git引用均被拒绝。从现行源码提取`renderTip`，冻结上下文后验证转义、缺失路径和两种原型路径，`Object.prototype`属性描述保持一致；该夹具仅模拟文本转义，不代替完整jQuery/浏览器测试。highlight.js的JavaScript、XML、PHP三种语言共观察874次正则构造，样例正文未成为模式；未知语言回退的动态提示没有替换参数，另一条提示格式固定。

该次复核完成告警归类和上述调用边界检查，没有确认需要修改业务代码的缺陷，也未重跑浏览器、全库依赖漏洞或敏感信息扫描。当时保留版本标签的既有取舍、四个vendor排除项及未覆盖的语言/长输入风险；后续依赖升级仅删除已退役Iris的失效排除项，现行范围见Decision。

## Local dependency validation 2026-10-08

依赖升级后的本地补验使用actionlint1.7.12检查仓库工作流，通过且无输出。Windows发行包先与上游校验文件核对SHA-256 `6e7241b51e6817ea6a047693d8e6fed13b31819c9a0dd6c5a726e1592d22f6e9`；匿名GitHub API限流、直接HTTPS请求超时后，通过已配置的GitHub CLI取得同一公开发行包，没有修改工作流的Action引用。

本地Semgrep1.178.0使用显式`p/default`、`p/php`、`p/javascript`、`p/github-actions`规则集与`--metrics off`，对主题脚本、后台资源入口、WordPress smoke、vendor补丁工具、CI YAML、PJAX/Fancybox独立文件及实际合并包共8个文件执行543条规则。结果为CI YAML中8处既有可变Action标签提示，引用本轮均未改变。`auto`与关闭metrics的组合在扫描前被工具拒绝，因此这里不声称与CI的auto配置覆盖一致。

扫描虽然退出0，JSON仍记录10项Pro引擎能力限制、2项YAML规则匹配错误、3项合并包超时及5项部分解析记录；涉及Actions表达式/文本和Fancybox压缩语法。不能将这些目标视为全部规则已完成，也没有通过扩大排除项或改写第三方源码隐藏限制。Windows JSON输出按实际编码读取后提取结果，解析诊断与发现分开记录。

对本轮修改及新增、未被Git忽略的文件执行私钥头、GitHub令牌、AWS访问键和Telegram令牌四类限定模式检查，未命中。它不是全库秘密扫描或完整供应链安全保证。直接依赖公告查询与版本/许可选择继续见[前台迁移](../architecture/2026-10-08-jquery4-and-plugin-migration.md)及[HLS升级](../architecture/2026-10-08-hls-loading-and-upgrade.md)；本地六组运行时与打包结果见[兼容性补验](../testing/2026-10-07-compatibility-evidence.md#dependency-upgrade-validation-2026-10-08)。

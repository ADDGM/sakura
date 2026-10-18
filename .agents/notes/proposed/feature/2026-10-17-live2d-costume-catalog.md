# Agent Note: Live2D 扩展服装先建立模型兼容目录

Status: proposed

## Problem

看板娘默认从 Tia/Pio 各自的三套内置纹理中随机选择，另支持可选外置清单。根级完整服装集合未随主题接入，首批五套仅在维护者测试站独立部署验证；相同图片尺寸、相似名称或 `pioUrl` 字段不能证明纹理适配某个模型。直接启用整个集合可能造成脸部、衣服或动作错位，也会扩大主题安装包。

2026-10-17 的后续规划在既有基础功能之上展开。本篇是这次具体方案的成文日期；大型服装作为后续方向已在[基础接入决定](../../implemented/architecture/2026-10-07-live2d-integration.md)中保留。当前已完成资源调查、214个配对的离线映射及首批五套同站点外置清单验收，新增公开分发依据仍未补齐。

## Proposal

2026-10-18维护者进一步授权完整服装库、默认CDN、可选分片下载及仓库路径规范化，[模型关联服装库](../../implemented/feature/2026-10-18-live2d-managed-costumes.md)接管整库交付并已在本地实现。107PNG迁入 `live2d/costumes/pio/textures/` 且字节不变，清单去除三份内置重复后为104套；以下保留原映射、首批候选取舍和来源边界，历史路径代表调查当时状态。

先为根级 107 套候选建立独立的 Tia/Pio 视觉兼容目录，再从通过验证的候选中选择首批少量服装。以 Pio 的三个字节相同样本建立对照，但不预先认定整个集合都属于 Pio；Tia 单独判断。没有通过视觉验证的条目保持待定，不能进入随机选择池。

最初的分发建议是沿用模型目录的 `textures.json` 和现有随机选择行为，只补充确认适配的少量纹理及清单条目。维护者随后提出主题上传体积限制，已实施的[外置服装清单](../../implemented/architecture/2026-10-17-live2d-external-costume-catalog.md)将扩展素材独立存放，接管本篇的小批随主题分发方式；本篇继续负责模型映射、具体候选与来源依据。加载能力已用内置纹理和首批五套新增外观验证；根级完整素材集合继续排除于主题安装包，新增公开分发仍待来源审核。

2026-10-17维护者确认先执行离线映射，并提出在看板娘上增加便捷换装按钮。[换装按钮已本地实现](../../implemented/feature/2026-10-17-live2d-costume-button.md)，支持现有模型专属清单、键盘及失败恢复。根级素材没有因为按钮出现而进入选择池，新增素材仍按本篇独立确定范围。

### Current evidence

本轮直接读取[根级清单](../../../../live2d/textures.json)与 PNG 文件，检查路径、PNG 分块 CRC、SHA-256，并与两模型内置纹理逐字节哈希比较：

| 检查对象 | 本轮结果 | 能够支持的结论 |
| --- | --- | --- |
| 根级服装集合 | 107 个条目、107 个文件、52,094,336 字节（约 49.68 MiB） | 无缺失、未列出文件、重复 ID、重复路径或重复内容 |
| PNG 格式 | 全部 1024×1024，PNG 分块 CRC 通过 | 文件结构和传输完整性，不代表图像解码或 WebGL 视觉兼容 |
| Pio 内置纹理 | 下表三对 SHA-256 完全相同 | 三份基础纹理已有副本，无需作为新增服装重复引入 |
| Tia 内置纹理 | 三份均无根级哈希匹配 | 没有取得字节相同的对照，不能据此判定全部不兼容 |

| Pio 内置条目 | 根级同内容条目 |
| --- | --- |
| `default-costume` | `basic-costume` |
| `school-costume` | `magic-academy(brown)` |
| `pajamas-costume` | `pajamas-costume` |

根级 `default-costume` 与 Pio 内置的同名条目不是同一文件，映射必须引用路径和哈希，不能只用名称。扣除 Pio 已有的三份副本，仍有104份不同内容候选，共50,456,340字节；映射结果见下节。

根级文件随 Sakura 的 `0d1a0cb92119035a584a8a3024e9e495b91e67cb` 导入。已知[H-Siren 固定提交的 live2d 目录](https://github.com/galnetwen/H-Siren/tree/79bcde23ccca45f313d7ba08d3af5e3369b598a3/live2d)只有 `css`、`js`、`message.json`、`model`，没有这批根级 `textures` 或 `textures.json`。这不否定基础模型已有的来源比对，但无法证明根级集合来自同一上游。使用与分发依据沿用[来源边界](../../implemented/architecture/2026-10-07-live2d-integration.md#resource-provenance)独立记录；不把图片可访问、文件同源或主题 GPL 当作原作者授权。

### Mapping results

2026-10-17使用Chrome155.0.8059.40和当前Cubism2模型离线渲染：两模型各3份内置对照正常，107×2配对全部完成模型就绪与截图。逐项检查22张联系表，每套包含idle第1项、flick_head第0项、tap_body第0项触发750ms后的三个画面，各组帧哈希存在变化。

| 模型 | verified | incompatible | inconclusive | 观察 |
| --- | --- | --- | --- | --- |
| Pio | 107 | 0 | 0 | 可见脸部、头发、躯干、服装及翅膀未见明显UV错位 |
| Tia | 0 | 107 | 0 | 眼睛、头发和服装片段大面积贴到错误表面，内置对照正常 |

`verified`仅指280×250看板娘Canvas中三个抽样画面的可见区域，不覆盖全部动作时间点、屏外身体区域或其他模型版本。Pio模型SHA-256为`1545fdb296bb2d1c9c6f1c295b3a336705618aa467c45e9be8dbc9cb1ffb704d`，Tia为`f19c7a20bf7eafcc1ef303b9c9c52d0face97aa0990129aeddec8d5e089d1ef5`；模型或纹理内容改变后重验。映射采用换装连接加入前的运行时，SHA-256为`1b59d8f6b52bbc8fbd7dc76c88595bc2f337eea7a9ae7053a8f40c880c21a0a3`，只临时插入只读模型观察入口。按钮验证另外运行，不能混淆两次运行时版本。

每条原始记录均出现一条未附URL的`net::ERR_FAILED`资源诊断，但就绪、动作截图完成；相同运行时的两份内置对照诊断补采样未复现，原因未确认。因此这些视觉结论不能表述为全部请求或控制台零错误。

首批Pio候选为以下5套：覆盖不同外观、没有内置哈希重复，总原始图片2,633,148字节（约2.51MiB，非ZIP增量）。2026-10-18独立候选包已由维护者上传，并完成测试站验收；新增公开分发依据尚未确认，来源核对的进展见下节。

| 根级PNG条目 | 字节 |
| --- | ---: |
| `cherry-fairy` | 488,949 |
| `lolita-suit(sky-blue)` | 489,434 |
| `forest-witch(green)` | 621,567 |
| `hanbok(pink)` | 502,529 |
| `cat-witch(white)` | 530,669 |

### Candidate package preparation

2026-10-18同站点外置加载已用既有服装完成验收，维护者继续推进不同外观。候选包使用上述5份现有仓库PNG，保持图像字节不变，放在`wp-content/uploads/sakura-live2d/pio/pack-v1/`；它与先前重复内置外观的`verify-v1`分开。维护者已手动上传并配置该清单，测试站验收不扩大公开分发范围。

清单使用既有version 1格式和当前Pio模型指纹。输出文件名把括号颜色改成短横线，例如`lolita-suit(sky-blue).png`变为`lolita-suit-sky-blue.png`，以满足现行路径规则；原文件不重命名，PNG不重新编码。三套内置纹理不进入候选包，主题仍提供基础服装；大集合与新增图片不进入主题ZIP。

来源比对仅涉及选中的5份文件。[Asagiriran/Pio_Live2D固定版本](https://github.com/Asagiriran/Pio_Live2D/tree/c0927a6f18fcf8d9ff5635d98ed68f6b5082ccae)中找到全部五份相同副本；2026-10-18通过GitHub Contents的JSON/base64在内存取得PNG，五份SHA-256与字节数均和本地候选一致，没有落盘下载图片。

| 本地根级PNG | 已核验分发仓库路径 |
| --- | --- |
| `cherry-fairy.png` | `textures/texture_67.png` |
| `lolita-suit(sky-blue).png` | `textures/texture_39.png` |
| `forest-witch(green).png` | `textures/texture_17.png` |
| `hanbok(pink).png` | `textures/texture_27.png` |
| `cat-witch(white).png` | `textures_1/texture_122.png` |

该固定版本的两份JSON记录模型配置，`motions/readme`与`textures_1/read`均只有一个换行；仓库许可元数据为空，所查文档与提交说明未给出原作者署名或分发授权。同源副本便于追溯，但不能把仓库所有者直接认定为素材作者或授权人。

[fghrsh/live2d_api固定目录](https://github.com/fghrsh/live2d_api/tree/02dc5b3aaf5cee35455c816596820055c5363205/model/Potion-Maker/Pio/textures)还包含两份匹配：`hanbok-costume-pink.png`的Git blob为`986cfa5ab21ce0ed905d074f46c18443e7a75672`，与本地`hanbok(pink).png`相同；`witch-costume-white.png`的blob为`f5577bf43d88b943500cb550c35b13a0e3621c9d`，与本地`cat-witch(white).png`相同。

该仓库[版权说明](https://github.com/fghrsh/live2d_api/blob/02dc5b3aaf5cee35455c816596820055c5363205/README.md)将模型权利留给原作者，不能由API代码的MIT许可推定图片授权。另查到的[ViewerEX分发仓库](https://github.com/longhao-li/Live2DViewerEX-PotionMaker-Pio/tree/3ef798f2cc0479727f7d2b9a8d974e39a79f79cb)虽有CC BY-NC-SA文本，73项完整树未匹配这5个Git blob，也未取得对这批PNG的原作者授权依据，不套用为候选包许可。既有[来源边界](../../implemented/architecture/2026-10-07-live2d-integration.md#resource-provenance)继续适用。此次已完成有明确入口的副本比对，原作者授权待新线索补查，不重复把公开副本数量当作授权进展。

本地候选ZIP为7文件、2,568,754字节，包含5PNG、JSON和来源/部署说明；CRC、安全路径、PNG解码及逐文件源哈希通过。当前Pio运行时在Edge155.0.4283.62中经本地HTTP逐套换装成功，模型保持、旧GPU纹理释放、无预取，860/861px边界通过，pageerror为空。确定性逐套检查之后，自然随机候选池也完成一次按钮切换。

构建3.7.0-dev.87测试站的JSON和5PNG均返回200，字节哈希与候选包一致。前台Pio清单指向pack-v1，旧远程模式未启用；12次普通按钮操作覆盖五套新增外观，另完成文章页换装及返回首页后的Enter换装，共14次成功，模型、Canvas保持且旧GPU纹理释放。页面往返通过PJAX，未预取新增PNG，pageerror与Live2D错误为空；五套实站画面已核对。首轮脚本等待完整load超时，补采样确认页面仍为interactive但PJAX已完成，改按DOM与PJAX完成事件验证通过，未修改主题导航。860/861px仍属此前本地证据，不冒充实站检查。

安装与验收结束后，本地候选ZIP/checksum和冗余实站截图已退役，保留来源、哈希、合成预览与测试结果，服务器资源不受清理影响。本篇保持proposed，范围是尚未核清来源依据的新增公开分发；上述五套同站点技术验收已完成。

### Mapping procedure

1. 使用仓库现有 Cubism 2 运行时和两份 `.moc`，由本机隔离页面加载候选纹理。固定视口、缩放与截图时机，首先渲染两模型各自三套内置纹理，确认夹具和基线正常。
2. 逐一记录每个“候选纹理 × 模型”的结果，共 214 个组合。每次独立加载，等待模型和纹理就绪，记录候选与 `.moc` 的 SHA-256、浏览器版本、请求错误、模型错误和截图。
3. 对照正常基线检查面部、头发、躯干、四肢、衣服边缘及透明区域，并观察待机、头部和身体交互动作。自动化可以确认加载和像素变化，视觉错位仍需检查截图或实际动画，不能以 HTTP 200、非空 Canvas 或同为 1024×1024 判为适配。
4. 每个模型分别标记 `verified`（规定场景已通过）、`incompatible`（有明确错位证据）、`inconclusive`（夹具、资源或观察不足）。请求失败不能直接判为纹理不兼容。保留失败类别和必要证据，避免无限重试；没有跑到的组合保持 `not_run`。
5. 汇总每模型有效候选、与内置纹理的重复项、来源状态及候选实际字节数。只有新增且通过验证的条目进入首批候选；首批具体清单是下一次接入范围审核的输入。

映射结果属于验证证据，不直接作为浏览器运行时配置。现阶段不引入新的生产 JSON 协议或数据库选项。素材或模型哈希变化后，相应配对的旧视觉结论需要复验。

### Integration boundary

原小批内置方案原本涉及相应的 `live2d/model/{tia,pio}/textures/`、`textures.json`、正式资源/loader 回归和相关笔记；现由已另行批准并实现的[外置方案](../../implemented/architecture/2026-10-17-live2d-external-costume-catalog.md)接管分发。内置 `pioUrl` 结构继续使用，模型专属列表不混用；Pio 新增能力不要求 Tia 同时找到新候选。本篇的具体素材范围与来源确认仍未完成，不能由架构实现替代。

总开关、861px 冷加载边界、提示与拖动、PJAX 单实例、远程模式选择条件继续遵守已有契约。远程 PNG 路径的错误回退是另一项行为变更，不借本次清单扩展修改它。多纹理槽、新动作或新模型格式不作为单 PNG 服装接入，发现这些需要时单独评估。

## Existing record audit

本轮在 proposed、implemented、rejected 中按 Live2D、服装、纹理与清单检索，只有 implemented 命中；没有可直接接管本提案的旧服装方案。

- **部分重叠，保留职责**：[基础接入](../../implemented/architecture/2026-10-07-live2d-integration.md)负责加载、资源和排包边界，与本篇互链；[看板娘设置](../../implemented/feature/2026-10-07-live2d-settings-tab.md)负责四个现有配置项；[浮层位置](../../implemented/bug-fix/2026-10-07-live2d-player-layout.md)和[部署边界](../../implemented/testing/2026-10-07-live2d-deployed-boundaries.md)负责布局与历史验收；[兼容性证据](../../implemented/testing/2026-10-07-compatibility-evidence.md)负责验证层级；[正式发布](../../implemented/process/2026-10-07-stable-release.md)保留基础包排除大集合的历史理由。没有吸收或改写这些决定。
- **不承接本决定**：核心资源策略及构建引用、jQuery/HLS 迁移、质量流程、导航断点、原生更新和首页视频笔记虽然提及 Live2D，但各自负责通用资源、其他模块或测试结果；不把它们改成服装方案。

## Alternatives considered

- **验证后小批扩充模型专属列表（最初推荐，现与外置方案重新比较）**：复用现有加载与安装方式，无需新增配置或网络契约；代价是首批服装数量有限，每次新增仍需审核兼容、来源和安装包体积。它能先交付可验证的扩展，不把完整素材库接入成本提前引入。
- **整体随主题分发根级 107 张纹理**：用户安装一次就能取得全部素材，部署步骤最少；但原始图片就增加约 49.68 MiB。最初还缺少模型映射；映射完成后Pio规定场景通过、Tia错位，因此仍不能跨模型随机使用，来源与包体积约束也未消失。
- **独立可选服装包或静态托管目录**：能承载更多素材并控制基础包大小；需要定义包与模型版本、安装位置、清单缓存、资源缺失及更新时的回退行为。若映射后的有效数量或体积使小批内置方案不能满足目标，再以实际结果设计这条路线。
- **继续只使用远程随机 PNG 接口**：已有接入、主题包无需增大；但接口不提供本方案需要的固定候选身份和逐项验证清单，也不能解决根级集合的可追溯选择。保留当前可选能力，不把它当成本地服装支持的完成证明。

## Acceptance criteria

- 调查阶段：107 个条目均有文件路径、字节数、PNG 检查和哈希；内置重复项、来源缺口和未验证范围明确。本轮已完成。
- 映射阶段已完成：214个配对逐条标注结果，两模型内置对照通过；结论限定于上述规定动作、画面与哈希，原诊断及未覆盖范围保留。夹具失败不冒充不兼容结果。
- 接入阶段：首批候选名称、路径、模型、来源状态及新增体积已确认；仅这些条目进入对应模型清单。现有 loader/资源回归、两模型桌面渲染和 860/861px 冷加载检查通过；大集合仍不在主题包内，现有内置样本与远程模式保持可用。
- 每阶段收尾清理用毕临时服务、依赖、脚本和冗余截图，保留映射数据、必要对照与问题证据；没有新安装包的阶段无需重新打包主题。

## Risks

逐套静态截图无法覆盖全部动作时间点；结论限定于记录的模型哈希、运行时和验收动作。内置纹理哈希相同只支持内容身份，不提供新的许可依据。根级素材来源尚未补齐，代码维护和本地兼容验证可以继续，新增分发范围需要单独核对依据。

本轮工具只暴露内置浏览器及 MCP Apps，没有真实 Safari 或触屏入口；后续 Chromium 映射也不能关闭真实设备验收待办。暂不更换运行时，避免同时改变模型解释和服装输入，使问题难以定位。

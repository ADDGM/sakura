# Agent Note: HLS 共用按需加载与运行时升级

Status: implemented

## Problem

2026-10-08按`c842725`核对前端依赖。[封面初始化](../../../../js/sakura-app.js)仅在页面存在`video.hls`时加载`cdn/js/src/16.hls.js`，而APlayer对`type: hls`或自动识别的m3u8音频直接调用全局`Hls`。没有HLS封面且其他代码未预载该库时，音频选择会抛出`Hls is not defined`，连原生HLS回退也无法到达。现有APlayer版本升级本身不能解决这个加载顺序问题。

内置hls.js仍是0.12.2。新版本保留当前使用的接口，但跨主版本存在缓冲、低延迟、事件与媒体时间轴变化，不能仅替换文件后根据语法检查宣布播放兼容。

2026-10-09对`631798b`部署包补验时，HLS首次播放和暂停恢复正常，但从其他文章返回缓存页面后视频地址为空、时间停在0。jquery-pjax的缓存恢复只触发`pjax:start`、`pjax:beforeReplace`和`pjax:end`，不会触发Ajax专用的`pjax:beforeSend`与`pjax:complete`；原接线遗漏了历史导航的清理和重建。

## Decision

首批限定HLS加载和hls.js。维护者于2026-10-08确认按HLS、jQuery与插件、后台依赖分批升级；本篇持有首批实现的加载与资源契约。

1. [主题脚本](../../../../js/sakura-app.js)的`ensureHls()`检查真实全局库，复用进行中的请求，成功和失败后均释放请求状态。加载超时为15秒，失败打印诊断并允许后续初始化重试。封面与含HLS曲目的列表共用该入口；普通音频不额外请求库。类型识别与APlayer一致，显式normal不被改写。
2. 需要HLS的列表在库就绪后创建APlayer，覆盖首曲和后续曲目。初始化代次与容器连接状态共同拦住过期歌单、脚本和DOM ready回调；重复初始化会销毁旧播放器。封面单独保存HLS实例，重新初始化与PJAX离页时销毁；过期manifest回调不会重新播放旧视频，浏览器阻止自动播放时保留手动按钮。
3. [独立HLS文件](../../../../cdn/js/src/16.hls.js)为官方npm `hls.js@1.7.3`完整UMD `dist/hls.min.js`，字节与下文固定SHA-256一致，并随附[Apache-2.0许可](../../../../cdn/js/src/16.hls.LICENSE.txt)。现有路径保留，HLS仍不进入合并包，不引入ESM或独立worker部署配置。
4. [HLS自测](../../../../scripts/tests/hls.test.cjs)使用Node内置测试器及内置APlayer真实`setAudio`，验证按需、并发、失败重试、旧回调、销毁和原生回退。真实浏览器播放与完整站点验收的范围分别记录，不把模拟媒体元素视为Safari。
5. HLS封面在`pjax:beforeReplace`释放，在`pjax:end`初始化；首次整页加载仍由`normalize()`初始化。`ini.pjax()`不重复初始化封面，普通Ajax导航每次只创建一个实例，缓存前进/后退也经过同一生命周期。此调整只覆盖HLS，其他组件的PJAX接线保持其原有职责。

APlayer 1.10.1、jQuery、Iris及核心资源本地/远程开关不在首批修改范围。迁移完成后仅分发所选新版，不保留旧库作为自动降级副本；浏览器原生HLS回退继续使用当前浏览器的媒体实现，与运行旧版hls.js无关。库升级不等于获准提交、推送或部署。

## Dependency inventory

| 依赖 | 当前文件与实际使用 | 2026-10-08上游核对 | 首批范围 |
| --- | --- | --- | --- |
| APlayer | [独立副本](../../../../cdn/js/src/07.APlayer.min.js)与[合并包](../../../../cdn/js/lib.js)均为1.10.1；合并包省略sourceMappingURL | [最新正式Release](https://github.com/DIYgod/APlayer/releases/tag/v1.10.1)与npm latest仍为1.10.1 | 保留版本，修正调用方的HLS加载顺序 |
| hls.js | 独立副本已由0.12.2替换为1.7.3；封面与APlayer共用按需加载，不在合并包中 | [v1.7.3](https://github.com/video-dev/hls.js/releases/tag/v1.7.3)，npm latest一致 | 已替换完整UMD版，主题不保留旧版本降级副本 |
| jQuery | [独立副本](../../../../cdn/js/src/01.jquery.min.js)与合并包已由3.5.1同步替换为4.0.0 | npm latest为4.0.0 | 第二批已连同PJAX/Fancybox迁移；详见[前台升级](2026-10-08-jquery4-and-plugin-migration.md)，没有永久Migrate或恢复旧API |
| Iris | 原主题后备副本为0.9.14，现已删除；[Options Framework](../../../../inc/options-framework.php)直接使用WordPress的wp-color-picker | 上游标签1.0.7，master package.json为1.1.1；测试站宿主实际提供1.1.1 | 第三批移除旧后备文件和注册，详见[后台依赖](2026-10-07-admin-settings-boundary.md#native-color-picker-dependencies) |

对npm发行包先校验SHA-512 integrity，再在内存读取成员文件：APlayer 1.10.1和hls.js 0.12.2与本地文件规范化换行/末尾空白后一致；APlayer再去掉sourceMappingURL后能够在合并包中完整定位。这里没有重建合并包，也不推断其他所有vendor文件均与上游一致。

候选[1.7.3发行包](https://registry.npmjs.org/hls.js/-/hls.js-1.7.3.tgz)中的`dist/hls.min.js`为619,692字节，SHA-256为`a12e7ee1cd64a69dcdb314157e45dafcba705bfb0b1440b7935cb265d374423e`；旧文件252,741字节。以同一本机Node gzip默认设置计算分别为189,212与72,019字节，不代表部署服务器的实际传输体积。新版只影响需要HLS时的加载，但包体积成本仍需接受。[上游LICENSE](https://github.com/video-dev/hls.js/blob/v1.7.3/LICENSE)随升级一并保留。

[官方迁移说明](https://github.com/video-dev/hls.js/blob/v1.7.3/MIGRATING.md)中的0.x章节以0.14.x到1.0.0为范围，并非0.12.2的完整直接迁移证明。它记录Promise要求、缓冲/低延迟默认值和事件变化；1.7还调整媒体时间轴偏移。1.4后的独立workerPath要求针对ESM，当前使用UMD，不能把两种部署方式混淆。

## Existing record audit

[核心资源契约](../../implemented/architecture/2026-10-07-core-resource-policy.md)继续约束本地默认、远程显式启用及固定标签，与本次实现部分重叠并互链；本次实现不更改它。[音乐缓存](../../implemented/architecture/2026-10-07-music-cache-contract.md)负责API令牌、响应缓存和旧nonce读取，[质量扫描边界](../../implemented/process/2026-10-07-quality-workflow-boundary.md)负责扫描口径与vendor排除，两者提供相关约束，职责不纳入本次静态依赖修复。没有已有HLS加载决策需要替换或归档，也不因升级而宣称告警清零。

## Alternatives considered

- **只替换hls.js文件**：修改范围最小，也容易核对发行包，但没有HLS封面时音频仍不会加载它，已复现的异常继续存在，因此不推荐作为完整首批方案。
- **所有启用播放器的页面都预载HLS**：加载顺序直观，也覆盖后续切到HLS曲目的情况；代价是普通MP3播放列表同样下载较大的库，不符合当前按需资源使用方式，因此不推荐。
- **封面和HLS播放列表共用按需入口，再升级完整UMD（采用）**：保留普通音频的请求边界并解决缺失依赖；代价是需要处理并发、重试和PJAX过期回调，且新版需要真实媒体回归。
- **先修加载、暂留0.12.2**：能以较小迁移风险独立验证加载修复，实施时先通过该检查点再替换1.7.3。它用于定位失败来源，不是交付包中的双版本降级策略。
- **在原生popstate监听器补一次HLS初始化**：改动位置集中且可以覆盖已缓存页面，但该事件也可能在缓存未命中的请求完成之前运行，或来自页内历史变化，不能作为新DOM已就绪的信号，因此不采用。
- **使用PJAX的替换前与结束事件（2026-10-09补验采用）**：插件在Ajax和缓存恢复两条路径上都提供这些事件，能够先释放旧实例再为新DOM建立实例；代价是HLS生命周期独立于仅在Ajax完成后执行的通用初始化，回归需同时覆盖两种事件序列。

## Investigation evidence

从未修改的APlayer源码提取`setAudio`进行隔离执行：无Hls时MP3成功；m3u8抛出`ReferenceError: Hls is not defined`；定义`Hls.isSupported() => false`后，模拟原生支持的音频元素能进入回退。测试没有发出网络请求，仅证明调用顺序问题，不代表真实音频播放已验收。

0.12.2与候选1.7.3在Node VM中的`loadSource`、`attachMedia`、`on`、`destroy`和`MANIFEST_PARSED`均存在；无MSE环境的`isSupported()`返回false，实例创建/销毁通过。该探针不覆盖媒体解码、浏览器worker、CORS或真实网络。

OSV querybatch查询APlayer 1.10.1、hls.js 0.12.2/1.7.3、jQuery 3.5.1/3.7.1，均未返回匹配记录。查询仅覆盖这些直接包版本，不包括完整传递依赖、嵌入副本、Iris、未知漏洞或站点可利用性，不作为“全部依赖安全”的结论。

## Consequences

无HLS封面的音频与后续HLS曲目不再因缺失全局库中断，普通音频仍同步创建。需要HLS的播放器会等到库加载后显示；脚本失败时不创建半初始化实例，后续初始化可重试。新版体积增加，媒体缓冲、直播、长视频及特定流格式仍需要对应场景的回归；jQuery、Iris和vendor扫描边界由各自记录负责。

历史导航修复将HLS销毁时点放在新DOM即将替换旧DOM之前，旧封面在导航请求等待期间可继续播放；普通导航和缓存恢复共享同一清理时点，不在原生popstate到达时猜测页面是否已经就绪。

## Verification

2026-10-08先在0.12.2通过16项HLS测试，再替换1.7.3；新版运行`node --test scripts/tests/hls.test.cjs scripts/tests/live2d.test.cjs`，35项通过。测试使用真实APlayer类型推断和`setAudio`，浏览器媒体、网络和布局由独立检查覆盖。首轮vendor探针缺少VM的`window`导致导出检查失败，补齐浏览器全局后通过，未因此修改上游库。

Chromium 154.0.8037.98的临时HTTP夹具加载实际`lib.js`及主题HLS/播放器代码。普通WAV真实播放且零HLS请求；使用[Mux公开测试流](https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8)验证无封面HLS音频、普通曲目切至后续HLS、音频与封面并发，播放时间均实际前进，封面解码宽度1280。每个需要HLS的页面只加载一次1.7.3，worker实际创建，销毁封面后媒体解绑，四个场景无pageerror。该检查不代表完整634秒播放、直播、断网恢复或所有编解码组合均已验证。

原生HLS回退、自动播放拒绝及原生视频离页清理通过隔离模拟验证；清理会解绑metadata处理器、暂停、清空src并重置媒体元素，真实Safari尚未验收。第二批换用jQuery 4后已重验真实并发音频/视频播放与单次脚本请求。测试站与GitHub构建包的部署验收独立于本地升级，不因本篇落地而宣称已经发布。

2026-10-09部署包的未发布草稿使用主题现有图片和Mux测试流，通过正文`coverVideo`元素触发真实主题初始化。HLS实际解码1920×1080，播放时间超过212秒，暂停和恢复正常；Fancybox打开、双向切换、标题、关闭及历史返回后重开通过。HLS历史返回持续停在0且`src`为空，复现了上文生命周期缺陷；这一发现不推翻首播结果，也不表示特色图片与封面元数据生成路径或真实Safari已验证。

新增3项回归执行主题实际的初始化函数和PJAX事件绑定，仅对无关界面功能提供空桩；事件序列按jquery-pjax 2.0.1的Ajax与缓存实现分别触发。修复前19项HLS测试中缓存清理与原生清理两项失败；修复后19项全部通过，连同6项vendor一致性和19项Live2D共44项通过，JS语法与差异检查通过。

修复后的本地HTTP夹具使用实际合并包、HLS 1.7.3和当前主题的HLS/PJAX代码，无关界面为空桩。内置浏览器实际验证普通Ajax离页、缓存返回、缓存前进、再次返回和Ajax进入视频页：每次离页旧实例解绑，视频页只有一个绑定实例，返回两轮分别播放到37秒和51秒并解码1920×1080。文档标识始终相同，缓存恢复实际只出现start/beforeReplace/end；HTTP记录中HLS脚本只请求一次，捕获的error/warn为空。

Semgrep以p/javascript的309条JS规则定向扫描两个变更JS文件，未返回报告；JSON另有6条Pro引擎能力错误和1条硬编码Basic认证规则超时，不能用命令退出0将其表述为无错误扫描。该超时规则按原注册表规则单独以30秒上限重跑，0报告、0错误；6条Pro能力错误仍保留，未增加抑制或修改规则集。没有依赖版本变化，不扩展为整个站点安全结论。该修复仍待提交、CI及测试服务器部署复核，当前服务器`631798b`不包含此修复。

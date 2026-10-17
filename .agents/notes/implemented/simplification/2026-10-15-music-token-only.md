# Agent Note: 音乐接口只接受站点签名

Status: implemented

## Problem

第一方歌单和资源 URL 已使用 `music_token`，前端与 PHP 仍留有 nonce 后备，服务端维护公开/私有两套成功路径。维护者确认没有主题以外依赖旧 nonce 的客户端，过渡协议不再有实际消费者。

## Decision

`/sakura/v1/meting/aplayer` 仅接受有效站点签名；缺失、过期、篡改、资源/类型/平台/配置指纹不匹配的 token 均返回 403。非空 `meting_nonce`、`_wpnonce` 或 `X-WP-Nonce` 也返回 403，包括混合有效 token 的请求，避免原私有请求被静默提升为公共缓存。

前台配置输出带签名的默认 URL 模板；播放器继续允许显式 `meting_api` 和 `data-api` 外部地址覆盖，但不再生成 nonce。Aplayer 每个资源直接生成签名，移除函数不存在时的旧协议后备。成功响应只走既有公开缓存；403/502 保留 private/no-store。保留其他 API 共用的 REST nonce 校验函数。

默认模板与资源 URL 使用 `add_query_arg`，适配普通固定链接下带 `rest_route` 的基址；Aplayer 参数先经 `rawurlencode` 编码，避免资源 ID 中的特殊字符改变查询结构。Meting 定义用 `require_once` 加载，允许同一进程处理多个 Aplayer 实例。

保留设置、接口路径、音乐 Cookie 仅服务端使用、token 绑定参数与到期、各类型缓存 TTL、歌词文本和 301 音频/图片重定向。[原音乐缓存契约](../../implemented/architecture/2026-10-07-music-cache-contract.md)与本篇部分重叠：本篇只替代旧 nonce 过渡策略，其余契约继续有效。[运行基线清理](../architecture/2026-10-15-supported-runtime-cleanup.md)负责登录和旧 WordPress 分支。

## Alternatives considered

- 保留 nonce 私有通道：可以继续播放旧页面缓存，也兼容外部脚本；维护者确认没有外部消费者，继续维护双协议的价值有限，部署时刷新旧页面与静态缓存即可。
- 有效 token 的混合请求一律公开缓存：实现更短，但改变原带 nonce 请求的缓存边界；选择明确拒绝混合请求。
- 连同其他 API 的 REST nonce 一并移除：可减少共享代码，但会破坏当前需要 nonce 的接口，音乐协议收窄不改变这些接口的认证要求。

## Consequences

音乐接口只维护站点签名一条成功路径。旧缓存页面或自定义地址若仍发 nonce 会得到 403，安装后必须刷新页面和相关缓存；不能把此失败降级成匿名代理。已有公开缓存仍可能在 TTL 与 stale-while-revalidate 窗口内被复用，本轮不承诺即时撤销所有中间缓存。

## Verification

2026-10-15，WordPress 7.1 / PHP 8.2.33 / SQLite 中执行[真实处理器回归](../../../../scripts/tests/music-api.php)：54 个请求场景覆盖有效 token、缺失/过期/篡改/参数或配置不匹配、三种旧 nonce 与混合请求、状态码及缓存头；另验默认模板、8 类基址/资源组合和其他 REST nonce 行为。测试使用内存选项与 Transient 夹具，不访问音乐平台或真实 Cookie。

多请求首次复现 Meting 类重复定义，改为 require_once 后通过；普通固定链接首次产生 5 项 URL 失败，add_query_arg 修复后完整 smoke 通过。[前端模板回归](../../../../scripts/tests/music-template.test.cjs)的 3 项检查实际代码片段，验证签名默认、全局和逐元素地址覆盖，包含于本轮全部 79 项 Node 测试。相关 PHP 语法检查通过；隔离测试不代表音乐平台实时可用性或测试站已更新，也未重跑六组 WordPress/PHP 矩阵。

2026-10-16 部署后实测：签名歌单返回 200 和 56 首曲目，缺少 token 及混合 meting_nonce、_wpnonce、X-WP-Nonce 的四种请求均返回 403，并带私有禁缓存头。M18 与 memories of... 的主题资源接口返回 301，上游 Range 请求返回 206、audio/mpeg 和 ID3 文件头。首次浏览器播放出现一次 NotSupportedError 并自动切曲；刷新后 M18 从 00:31 推进至 00:45，PJAX 进入文章后继续播放，未再次出现错误提示。此结果确认当时恢复可播，不证明首次异常根因已修复，也不承诺音乐平台持续可用。

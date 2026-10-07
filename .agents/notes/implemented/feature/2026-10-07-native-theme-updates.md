# Agent Note: WordPress 原生主题更新的渠道与安装包边界

Status: implemented

## Problem

仅展示 GitHub Release 状态不能让 WordPress 获得可安装的主题更新。接入原生更新后，主题必须提供正确候选和安装包，同时保留管理员对渠道及自动安装的控制，避免将源码归档当作主题交付包。

## Decision

[style.css](../../../../style.css) 声明 `Update URI: https://github.com/ADDGM/sakura`。[release-info.php](../../../../inc/release-info.php) 通过 `update_themes_github.com` 接入，只处理匹配该 URI 的主题。主题提供候选元数据，版本比较、安装权限和安装执行继续由 WordPress 核心负责。

`sakura_release_update_candidate()` 在稳定渠道只考虑正式 Release；测试渠道同时考虑正式与预发布候选，选择版本更高且具有有效包的候选，排除草稿和标记不匹配的条目。没有有效候选时不返回可安装更新。旧 `tag`、`tag2`、`develop` 设置分别兼容到 stable/testing，未知渠道回退 stable。

`sakura_release_installable_package()` 要求合法的 `vX.Y.Z` 或 `vX.Y.Z-beta.N` / `-rc.N` 标签，以及严格匹配 `sakura-{version}.zip` 的资产名和非空 URL。GitHub 自动源码 ZIP、校验文件及其他名字的压缩包不作为自动安装包。此函数不执行 SHA-256 下载校验，不应把独立发布验收中的双哈希核对描述成更新器的内置安全能力。

## Administration and cache

渠道修改和手动刷新要求 `manage_options`；刷新入口验证 nonce。保存渠道前的过滤器保留非管理员原值，合法渠道改变或手动刷新时清理发布缓存与 WordPress `update_themes` 缓存。发布查询正常缓存6小时，错误缓存15分钟，错误状态与限流时间用于提示，查询失败不伪造可用版本。

更新 payload 返回 `autoupdate=false`，主题不主动开启站点自动安装。管理员可通过 WordPress 原生主题页面选择开启；这个默认值不等于禁止管理员启用自动更新。

## Existing record audit

[正式发布](../process/2026-10-07-stable-release.md)持有版本、构建来源、交付包与部署证据，本篇持有查询、渠道、安装包及权限契约，两者部分重叠并互链。构建来源展示独立于自动安装候选选择，不根据其显示正常推断服务器安装链路已经全部测试。现有提交规范、通知和 Live2D 笔记不覆盖此职责。

## Alternatives considered

原计划明确批准 WordPress 官方 Update URI 机制，并明确排除自动源码包与默认开启自动更新，但没有保存完整的替代更新机制比较。因此本节保留资料缺口，不把“第三方更新库”或“自行实现安装器”编造为当时已经比较并否决的方案。

已知的实际变化是从 Release 信息展示扩展到原生可安装更新；原文没有记录继续只展示的完整利弊，不能补写成历史选型结论。日后改换更新机制时需要重新形成有证据的比较。

## Consequences

用户通过 WordPress 原生入口查看和安装更新，主题无需维护第二套安装执行流程；代价是依赖 GitHub API、规范标签与准确的 Release 包命名，限流或缺包时不能提供更新候选。默认关闭自动更新保留管理员选择，但后台定时安装仍需要单独验收，不能由手动升级成功推定。

## Verification

本篇于 2026-10-07 对 `2a42534` 的 Update URI、候选选择、严格包名、payload、渠道权限和刷新缓存源码进行核对。历史方案没有可证明的首次提出日期，本篇记录的是本次形成的维护契约，不采用归档文件时间推定原决策日期。

2026-09-28 的历史 smoke 与隔离 WordPress 记录分别覆盖候选/权限断言和15项原生升级检查；2026-10-07 正式部署记录覆盖 v3.7.0 包来源、配置保留，以及用户确认“立即更新”路径后的直接版本核验。更新查询同日16:07恢复。手动升级证据不等同于独立抓取服务器 PHP 下载日志，后台定时自动更新仍未验收。本轮不访问服务器或重新执行安装。

实际发布来源与升级后的核验结果见[正式发布笔记](../process/2026-10-07-stable-release.md)；原始执行记录未随仓库分发。

## Historical installation evidence

2026-09-28的ec55e3f测试站只验证开发来源展示、stable渠道、原生更新提示与管理员“立即检查”；“启用自动更新”按钮表示当时关闭，未点击安装。同期smoke覆盖渠道、严格包名、其他主题数据保留和非管理员分支，但没有执行真实HTTP伪造权限请求，不能把这些断言扩展成完整网络或安装验收。

同日隔离WordPress通过真实 `wp_update_themes()` 和 `Theme_Upgrader::upgrade()` 完成15项检查：更新候选及 `autoupdate=false`、升级成功、激活状态和设置保留、maintenance清理、旧build-info移除、五个修改文件哈希及原交付ZIP保留。上游更新响应和Release候选由夹具提供，下载钩子返回本地副本，邮件/外部服务请求被阻断；因此没有覆盖GitHub下载网络、HTTP上传、FTP/服务器权限或定时自动安装。

隔离安装夹具曾因目录层级错误导致末尾校验失败，升级本身已成功；下载钩子直接返回原交付ZIP还会使核心清理过程删除它。修正方式是返回隔离副本并验证原包仍在，不把夹具错误归因主题。来源HTML另测无元数据→Unknown且无提交/分支下载链接、合法标签→Release tag、非法分支/标签/SHA→未知；标签数据是本地夹具，直到后续正式部署才取得实站证据。

这些历史摘要于2026-10-07从原生更新和隔离安装两份报告归并，原报告及附件按用户授权退役；当前手动升级与定时自动更新的边界仍以上述Verification为准，未重新执行安装。

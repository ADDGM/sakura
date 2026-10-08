# Agent Note: 兼容性桥接的分层验证与证据范围

Status: implemented

## Problem

旧主题跨到新版WordPress/PHP后，语法通过仍可能在登录态、外部API、页面替换或真实部署中失败。烟雾测试自身也曾因字符串插值、换行及过期源码断言失败，需要区分测试夹具故障和主题缺陷。

## Decision

沿用兼容性桥接：保留设置键、主题模板和REST入口，以边界保护及局部修复维持既有行为；不将兼容升级扩大为主题重写。当前[ci.yml](../../../../.github/workflows/ci.yml)同时保留PHP8.0/8.1/8.2构建与WordPress7.0/7.1乘三PHP版本的六组smoke。

[scripts/wordpress-smoke.php](../../../../scripts/wordpress-smoke.php)组合源码契约与已加载WordPress的运行时检查。PHP lint、脚本自测、静态字符串存在性、模拟上游响应、真实站点浏览器验收是不同证据：不能由源码标记或模拟成功推导实际第三方可用、邮件送达、图床上传或WebGL渲染成功。

需要字面匹配的PHP变量保留字面量；源码检查时规范化CRLF，真实文件不因检查改写。实现契约变化时同步旧断言，先定位失败归属，不能为消除红灯直接删除保护。

后台颜色控件退役主题副本后，验证职责随实现迁到WordPress原生资源的注册、依赖与入队；不继续要求主题声明宿主的翻译字符串。smoke临时切换主题设置页screen并补注册原生后台脚本，检查主题入队前后的核心依赖对象、主题脚本依赖和样式队列，再恢复screen及其文章/分类上下文。HLS本地请求地址已有[HLS行为测试](../../../../scripts/tests/hls.test.cjs)直接验证，PHP smoke不重复绑定JavaScript字符串的引号格式，继续检查包内运行时文件和旧上游地址禁用规则。

翻译PO是编辑入口，MO由[仓库PHP编译器](../../../../scripts/compile-translations.php)生成，CI同时运行 --self-test 和 --check。编译支持上下文/复数，跳过fuzzy、obsolete及空翻译，空译文回退原文；不能仅凭MO可读取就认定它与PO及当前编译器一致。

部署回归需写清版本、入口、登录状态、视口及网络条件。动态漂亮链接落到Nginx错误页时先区分服务器路由和主题404；实际图片上传需要独立验证，不能由前端布局或模拟接口通过来推定。

## Existing record audit

与[Live2D边界验证](2026-10-07-live2d-deployed-boundaries.md)、[正式发布](../process/2026-10-07-stable-release.md)部分重叠，保留各自模块/版本证据；本篇提炼通用验证边界。旧中文提交类型已由[提交规范](../process/2026-10-07-commit-policy.md)替代，不沿用总计划的旧格式。历史smoke与MO故障、Beta/RC验收在本篇保留结论和覆盖范围。

## Alternatives considered

- 完整现代化重写：可以系统清理旧架构，但原计划选择桥接以保留设置和外部接口，先验证已确认的兼容风险。
- 只做PHP8.2静态检查：原草稿范围较小、执行成本低，但覆盖不到其他目标PHP和WordPress运行时；当前采用三PHP、六组WordPress矩阵，再按变更补实际场景。
- 复用任何工具生成的MO：历史出现“文件可反编译但二进制不一致”的失败，当前要求使用仓库编译器及同步检查。
- 仅删除升级后失效的四条源码断言：能消除误报，但后台原生依赖会缺少对应保护，因此以真实WordPress资源注册与依赖检查接管；HLS地址复用已有行为测试，不再增加重复字符串匹配。

## Consequences

失败可按层定位，旧配置和部署证据可追溯；代价是矩阵与实站检查的维护成本。源码契约仍会随实现变化而漂移，stub不会证明外部服务健康，目标版本矩阵也不代表所有插件组合受支持。

## Verification

2026-10-07按 `2a42534` 核对CI矩阵、翻译编译逻辑及smoke的日期、音乐、邮件、CRLF和字面变量检查。Beta.4、RC.1、v3.5.0的历史摘要与v3.7.0验收记录保留各自范围；已退役的原报告不再作为可获取附件。本轮为文档核对，未搭建WordPress、重跑业务测试或取得新部署证据。成文日不代表兼容升级首次提出日。

## Dependency upgrade validation 2026-10-08

本地依赖升级后的[完整smoke](../../../../scripts/wordpress-smoke.php)在WordPress7.0、7.1各自搭配PHP8.0.30、8.1.34、8.2.33时全部通过，六份WP_DEBUG日志均为空。测试使用工作副本生成的安装包、WP-CLI2.12.0、Windows与SQLite Database Integration3.0.2/SQLite3.53.2，未部署到用户站点。命令入口仍为`wp --path=<隔离站点> --url=http://localhost eval-file <主题>/scripts/wordpress-smoke.php`，管理员登录名沿用CI夹具的`admin`。

WordPress7.1/PHP8.2的两个反向检查分别在临时主题副本中移除`options-custom`的`wp-color-picker`依赖、改写原生颜色脚本地址；两者各产生一条预期错误。原文件逐字节恢复后，完整smoke再次通过。HLS的16项行为测试通过，包含实际请求地址断言；前三批的41项Node及真实Chromium验证继续以各模块记录为准，没有把它们改称本轮全量重跑。

三版PHP分别对63个仓库PHP文件通过语法检查，四项发布/元数据/翻译脚本自测及4份MO一致性检查通过。安装包遵循`.gitattributes`的父目录排除及CI的开发目录排除，397个文件逐一比对源码与SHA-256，ZIP CRC通过，新依赖与四份许可齐全，旧Iris/颜色控件、笔记与大型服装目录均未进入包。该本地快照为15,699,916字节、SHA-256 `2f3fa6e16e3a966f214aa9cba48420ce46ccb2fb35e17d4d05ee65c6162d3d71`；它保留源码基准3.7.0，是未提交工作副本的验证产物，不是GitHub构建或新的正式Release。

补验前，原`sourceChecks`段稳定复现三条已退役颜色控件翻译提示和一条HLS URL单双引号误报。首轮真实smoke又确认WP-CLI默认前台上下文未注册后台句柄，因而检查需要显式切换后台screen，而不是恢复主题旧副本。临时账号最初不叫`admin`导致权限样本缺失，按CI约定补齐后通过；没有因此修改现行权限逻辑。

SQLite插件要求至少3.37.0，下载的PHP8.0/8.1默认库分别为3.33.0/3.36.0，最初无法初始化数据库。本轮仅在这两个隔离运行时中备份并换入PHP8.2.33分发的SQLite3.53.2，实际版本探针通过；没有改全局PHP或把SQLite作为主题依赖。WordPress初次整包下载被截断，未通过ZIP校验；从官方`downloads.wordpress.org/release/`分段获取后核对每段范围、总长度及CRC，才用于测试。

六组结果不代替Linux/MySQL的GitHub CI，也不覆盖真实Safari、正式包部署或远程核心资源同步。当时远程标签仍为v3.5.0；2026-10-09的[构建资源引用](../architecture/2026-10-09-core-resource-build-reference.md)接管默认来源，记录新增的资源回归、六组矩阵和反向检查。运行时边界由[核心资源契约](../architecture/2026-10-07-core-resource-policy.md)持有；[质量检查](../process/2026-10-07-quality-workflow-boundary.md#local-dependency-validation-2026-10-08)另行记录规则覆盖限制。

## Historical fixture lessons

2026-09-28隔离环境为WordPress7.1.2、PHP8.2.33、SQLite Database Integration3.0.2/SQLite3.53.2，仅含测试数据；扩展通过PHP进程参数启用，没有复制用户数据库或修改全局PHP。实际核心升级不等于真实上游网络验证，夹具替换响应的范围见[原生更新笔记](../feature/2026-10-07-native-theme-updates.md)。核心、数据库和下载包已于2026-10-06清除，本次不恢复环境。

换行误报中的三项源码契约失败来自安装包CRLF与固定LF多行字符串不匹配，源码实际存在；只规范化读取字符串后，CRLF和LF正例均通过，故意移除enqueue标记的负例仍失败，r2安装后的完整smoke也通过。不能为了处理这种误报修改主题默认设置、真实文件或删除断言。

2026-09-23记录19项Node/19项浏览器及四份MO一致性通过；2026-09-28扩到23项浏览器，2026-09-30提交前复用该证据并核对五文件与r2包一致，未冒称全部重跑。历史翻译工具的38条提示和14条标签已落入正式message/PO/MO；现行编译入口仍是仓库PHP编译器。

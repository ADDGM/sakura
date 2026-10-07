# Agent Note: 后台配色与设置渲染保留存储边界

Status: implemented

## Problem

旧动态配色端点把颜色和用户CSS放进URL，长度与转义难以控制；主题设置CSS又覆盖WordPress通用控件。单纯视觉修补无法解决方案预览、旧配置和控件语义之间的长期耦合。

## Decision

[functions.php](../../../../functions.php)在 admin_init 注册 sakura/custom，保留用户资料 admin_color 标识。两种配色共用[静态CSS](../../../../inc/css/dash-scheme.css)，主题版本使用 sv 参数；颜色和Custom规则由内联CSS承载，不再进入资源URL。已保存方案与预览共用 sakura_dash_scheme_css()。

Custom七个颜色读取旧设置键，经 sanitize_hex_color() 校验并回退统一预设；可读前景色按背景对比度计算。源码内置默认CSS始终加载，附加字段的空值、纯注释或精确历史默认值按空附加规则处理；其他自定义CSS经去HTML标签及已知资源URL映射后追加。该处理不是任意CSS安全沙箱。

只映射已具备本地副本的旧樱花与Win10背景地址，分别指向 dash-sakura-bg.webp 和 Custom.jpg，覆盖HTTP、HTTPS及协议相对写法；不重写用户数据库。当前默认效果与附加覆盖分开维护，不把历史“保留失效外链”当作最终决定。

[Options Framework](../../../../inc/options-interface.php)保留原字段 name/id/value 和选项存储，补齐标签、组关联、普通radio/multicheck独立包装及上传控件名称；[设置CSS](../../../../inc/css/optionsframework.css)以主题设置容器为作用域。普通radio保持一项一行，视觉选择器不混入普通radio规则。主题设置资源随主题版本失效。

翻译调用使用 sakura 文本域；options_framework_theme 仍可作为旧存储后备键，不能全局替换。字段ID优先清理的具体日期契约见[建站日期](../feature/2026-10-07-site-runtime-date.md)。看板娘独立分组仍由[设置标签笔记](../feature/2026-10-07-live2d-settings-tab.md)维护。

## Existing record audit

创建前检索配色、Options Framework、存储及翻译，现有看板娘标签笔记只有分组契约，部分重叠并互链；[核心资源](2026-10-07-core-resource-policy.md)持有全站资源来源，本篇只负责后台渲染。原配色、语义化、国际化和早期radio修复归并到这里，间距微调保留历史。

## Alternatives considered

- 保留动态PHP样式端点：沿用原接口改动较少，但用户CSS进入查询串会遇到长度、编码及暴露问题；采用静态文件加内联值。
- 直接改写数据库旧URL：可清理持久值，但破坏原始自定义配置与回退依据；采用渲染时精确映射。
- 樱花背景同时分发PNG回退：覆盖更多旧浏览器，但旧计划权衡了包体积和后台目标环境，最终仅保留WebP，失败可退为纯色背景。
- 全面替换Options Framework：旧计划明确不进入Customizer/Site Editor迁移，先修渲染和语义，保留存储契约。没有记录完整替代框架比选。

## Consequences

配置兼容、预览和正式样式共用实现，第三方后台控件受影响范围收窄；代价是仍需识别历史默认CSS并维护原框架。管理员附加CSS可改变默认效果，不能承诺所有自定义配色均通过完整无障碍审核；8px间距等局部数值不是本篇新增的跨模块门禁。

## Verification

2026-10-07按 `2a42534` 核对注册、变量/默认规则、映射、字段ID清理、标签与存储后备键。原dev.33记录有Sakura/Custom/核心配色切换及桌面/782px/390px验收，Beta.6有动态中文提示验证；本轮未切换后台配色或保存设置。成文日不代表原功能首次提出日。

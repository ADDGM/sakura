# Agent Note: 测试邮件复用 WordPress 投递与已保存配置

Status: implemented

## Problem

维护者需要检查主题与邮件插件的连接，但主题不应另建SMTP配置，也不能将“请求已接受”误报成“收件成功”。内网站点地址还可能生成无效发件域名。

## Decision

[设置渲染器](../../../../inc/options-interface.php)通过按钮的 formaction/formmethod 提交到 admin-post.php，避免嵌套表单和额外AJAX链路。[sakura_handle_test_email](../../../../inc/mail.php)要求 edit_theme_options 和独立 nonce，收件人固定读取 admin_email，发件前缀只读取已保存设置。

按钮随主表单提交时可能携带未保存字段，但处理器不将它们保存或用于发送；“只使用已保存值”不等于浏览器完全不发送这些字段。主题不读取邮件插件的SMTP密码或内部选项，投递继续由 wp_mail() 与插件负责。

评论通知和测试邮件共用发件地址、headers和HTML外框；评论通知条件仍留在 [comment_mail_notify](../../../../functions.php)。站点主机是IP、localhost或无效域名时回退管理员邮箱的合法域名，无法生成有效发件地址则不发送。模板按内容上下文转义并限制HTML。

发送辅助函数监听 wp_mail_failed 并返回错误信息。后台成功文案仅表示 wp_mail()/插件接受请求，不证明最终送达。结果保存在按用户及随机token区分的一分钟Transient内，再固定安全重定向回设置页，避免刷新重复发送和任意跳转。

## Existing record audit

活跃笔记无测试邮件契约。与[建站日期](2026-10-07-site-runtime-date.md)共用一份旧计划，但业务无依赖，分别提炼；与[后台设置边界](../architecture/2026-10-07-admin-settings-boundary.md)仅共享渲染入口。

## Alternatives considered

- 复用 wp_mail() 和 Easy WP SMTP（采用）：主题只构造邮件，SMTP连接与认证仍由已配置插件负责。
- 主题新增SMTP设置或读取插件内部配置：原记录明确排除此扩展，避免重复配置及依赖插件内部存储；没有独立SMTP库选型过程可追溯。
- 原计划预留设置页独立按钮入口作为表单兼容回退，保留admin-post处理器；未将该回退描述为实际部署方案。

## Consequences

测试与评论邮件共享格式和发送能力，配置职责明确；代价是功能可用性仍取决于 WordPress及传输插件。邮件服务商可能覆盖From，最终送达需另有收件证据。测试邮件成功不能替代评论通知条件、第三方反垃圾和生产送达的验证。

## Verification

2026-10-07按 `2a42534` 核对表单动作、权限/nonce、固定收件人、已保存值、域名回退、公共发送和安全重定向。历史Beta.6记录包含Easy WP SMTP实际收件，本轮没有发送邮件、改SMTP或验证当前投递。本篇日期为维护契约成文日。

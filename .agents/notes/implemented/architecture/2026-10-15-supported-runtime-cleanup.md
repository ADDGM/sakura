# Agent Note: 按当前运行基线退役旧版本分支与登录依赖

Status: implemented

## Problem

主题最低要求已是 WordPress 7.0、PHP 8.0，但仍输出 WordPress 5.1 的评论分派标志、保留 4.4/3.4 判断，并在登录页额外加载 jQuery 1.9。登录背景缩放与滑块依赖旧事件接口，滑块同时用于前台注册页。后台 `dashboard-light.css` 已无运行时引用，当前配色实际使用 `dash-scheme.css`。

## Decision

以当前支持版本整理运行时代码。删除三处旧 WordPress 判断及 `Poi.reply_link_version`，评论继续使用当前 data 属性和委托事件。移除无引用的 `inc/css/dashboard-light.css`、`js/login.js`。`images/login_loading.gif` 仍用于评论上传预览和默认懒加载占位，保留该资源；仅撤除登录页对加载遮罩的依赖。

登录资源走 WordPress 入队接口，背景改为 CSS 居中覆盖，移除 jQuery 1.9、手工图片缩放和加载遮罩。`user/verification.js` 改为登录与前台注册共用的原生脚本，按表单保存滑块状态，处理指针取消、键盘和重复初始化；前台 PJAX 替换后重新绑定新表单。保留现有登录、注册、找回密码流程和 `login_validate` 开关，不再覆写所有表单的 `onsubmit` 或宿主“记住我”字段。

滑块仍只是客户端交互，不能代替 WordPress 密码、注册 nonce、权限和服务端验证。不得将原有隐藏字段协议描述为可靠人机验证。

维护者确认没有外部旧音乐客户端，旧 nonce 由[音乐接口只接受站点签名](../simplification/2026-10-15-music-token-only.md)独立接管。当前清理不改已保存的设置键、首页 `amv_url/amv_title`、后台自定义 CSS、历史提交解析、第三方插件公共参数或 HLS/WebGL 能力检测。`options_framework_theme` 是设置初始化异常时的后备键，尚未证明安全迁移，不随版本分支一并删除。

## Existing record audit

- [兼容性证据](../../implemented/testing/2026-10-07-compatibility-evidence.md)：部分重叠；本篇接管旧版本分支保留策略，六组支持版本与证据层级继续有效。
- [jQuery 迁移](../../implemented/architecture/2026-10-08-jquery4-and-plugin-migration.md)：部分重叠；该篇负责前台合并包，本篇处理独立登录依赖及共用注册脚本。
- [后台设置边界](../../implemented/architecture/2026-10-07-admin-settings-boundary.md)：部分重叠；只退役未使用样式，存储与当前配色规则继续有效。
- [音乐缓存](../../implemented/architecture/2026-10-07-music-cache-contract.md)：相邻契约；本篇不改变音乐鉴权与缓存。
- [核心资源](../../implemented/architecture/2026-10-07-core-resource-policy.md)、[首页视频](../../implemented/feature/2026-10-11-home-video-formats.md)：保留现用配置与资源来源边界。
- [评论初始化](../../implemented/bug-fix/2026-10-07-comment-form-initialization.md)：部分重叠；回复事件不再按旧版本分派，表单结构及上传逻辑保持原契约。

## Alternatives considered

- 登录页使用 WordPress 宿主 jQuery 并迁移旧事件：改动较少，也能删除 1.9 外链；但背景缩放和独立插件仍需依赖初始化顺序，前台注册又使用另一套 jQuery。本篇采用原生共享脚本，减少两种宿主间的耦合。
- 删除整个滑块功能：代码最少，也不会造成对客户端验证强度的误解；但超出旧依赖整理，会改变现用设置与页面交互，本篇保留功能并准确记录其边界。
- 一并删除所有带 legacy 名称的路径：能快速收窄源码，但现用设置与旧文章数据没有自动迁移，可能直接丢失显示效果；只移除已证明低于基线或不可达的分支。

## Consequences

登录背景无需 JavaScript 尺寸计算，登录页不再加载主题指定 jQuery；背景失败时显示底色并保持表单可用。共用控件以 WeakSet 保证每个节点只绑定一次，新 PJAX 表单重新锁定；键盘支持累积调整及 End 完成。维护成本收敛到一个原生脚本和样式，但仍需独立验证 Safari 与触屏交互。

部署后需刷新页面和静态缓存。原生“记住我”沿用 WordPress 字段和样式语义；删除原主题开关样式中隐藏原生字段的规则。不能从本地通过推定既有部署已经升级。

## Verification

2026-10-15，本地全部 79 项 Node 测试通过，其中共享滑块 6 项覆盖表单隔离、复位/完成、重复绑定及 PJAX 新节点；8 个相关 PHP 文件在 PHP 8.2.33 下语法通过。WordPress 7.1 / PHP 8.2.33 / SQLite 完整 smoke 通过，首次验证未生成 debug.log。随后工作副本快照的 406 个文件通过 ZIP CRC、源码/安装后逐项哈希核对，安装后的完整 smoke 通过；安装器产生三条 WordPress.org 安全连接失败警告，已与主题验证分开记录，不能将最终安装日志表述为空。本轮没有可用的 PHP 8.0/8.1 安装，未重跑三 PHP 或六组矩阵，也未运行远程 CI。

隔离 WordPress 浏览器中，登录、原生注册、找回密码、前台注册均验证未完成时阻止提交、完成后进入原 PHP 校验；正确账号登录成功。鼠标半程释放复位、到底完成和键盘 End 通过；“记住我”可见且能勾选/取消，Logo 查询参数保留，背景居中覆盖。首次截图发现旧开关 CSS 隐藏字段，修复后复验通过。

PJAX 进入注册页并后退/前进时，文档标记不变、控件和脚本各一个，新控件锁定；评论展开、回复移动及取消通过，未提交评论。两测试标签捕获的 error/warn 均为空。实际截图为 1280×720；390×844 视口覆盖未生效，不作为移动端证据。该轮隔离验证未检验真实 Safari/触屏、邮件投递、图床上传或测试站安装后的行为。

2026-10-16 维护者安装快照后，WordPress 7.1.3 测试站的四项新版公开脚本/样式与安装包 SHA-256 一致，两个退役文件返回 404；登录页呈现原生 range 并引用共享脚本/样式，维护者完成登录，随后确认已进入后台。实站评论展开、回复表单 DOM 位置移动及取消恢复通过，未提交评论，最终评论标签 error/warn 为空。站点关闭公开注册，未为验收更改设置或创建账号；真实 Safari/触屏、邮件和服务器 PHP 日志仍未覆盖。

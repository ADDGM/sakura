# Agent Note: Live2D 原生接入与模型资源边界

Status: implemented

## Problem

移植 H-Siren 的桌面看板娘时，直接复制页脚脚本注入会绕开 Sakura 的 WordPress 依赖、缓存和配置边界。旧 Cubism 模型与根目录的大型纹理集合也不能仅凭文件名判断兼容，维护时必须区分已接入能力与后续扩展。

## Decision

[functions.php](../../../../functions.php) 通过 `sakura_scripts()` 入队样式、Cubism 2 运行时、交互和一个 loader，并用 `SakuraLive2D` 注入配置；[footer.php](../../../../footer.php)负责容器和 Canvas。缓存策略复用[核心资源契约](2026-10-07-core-resource-policy.md)，不恢复直接拼接配置的页脚脚本注入。

基础模型固定为 Tia/Pio，保留 Cubism 2 的 `.moc` / `.mtn` 资源。[本地 loader](../../../../live2d/js/run_local.js)读取选定模型目录的 `model.json` 与 `textures.json`，沿用 `pioUrl` 清单字段；挑选并检查纹理条目后构造模型纹理地址。字段名不表示仅支持 Pio，也不意味着读取根目录的服装清单。

远程模式必须同时满足远程开关开启和接口非空，才选择[远程 loader](../../../../live2d/js/run_field.js)；该 loader 读取同一模型配置并替换 PNG 地址。两个 loader 都在依赖不可用、配置缺失或不满足 `min-width: 861px` 时退出，模型请求发生在该守卫之后。这个入口判断不等于已经加载后的运行时会在缩窄窗口时被卸载。

[.gitattributes](../../../../.gitattributes) 排除根目录 `live2d/textures/**` 与 `live2d/textures.json`，保留各模型最小纹理。大型服装须先建立模型—纹理映射并验证视觉兼容；Cubism 3/4/5 属于独立迁移评估，不纳入现有基础能力。

## Interaction and failure boundary

[message.js](../../../../live2d/js/message.js) 通过 document 委托和事件命名空间处理提示，`live2d_Tips()` 支持 PJAX 后重新初始化。提示配置保存翻译键，动态文本先转义再代入模板。拖动与播放器避让由[浮层位置契约](../bug-fix/2026-10-07-live2d-player-layout.md)负责；设置组织由[看板娘标签](../feature/2026-10-07-live2d-settings-tab.md)负责。

资源请求失败会输出诊断；当前远程 PNG 路径没有失败后自动切回本地模型的机制。保留外部接口、CORS 与响应可用性的边界，不能把本地夹具中的模拟远程 PNG 验证说成真实第三方服务已验收。

## Existing record audit

看板娘标签笔记只负责四个配置项的分组与保存兼容，[部署边界验证](../testing/2026-10-07-live2d-deployed-boundaries.md)只负责测试策略；两者与本篇部分重叠，保留互链，不吸收其完整验证过程。[正式发布](../process/2026-10-07-stable-release.md)继续负责版本与交付，不作为模块接入规范。

## Alternatives considered

- **原样移植 H-Siren 页脚注入**：改动较少且最接近原主题，但依赖顺序、缓存和全局配置依靠脚本字符串组织，PJAX 提示绑定也不适配现有页面更新方式，因此未采用。
- **按 Sakura 的 WordPress 结构接入（采用）**：复用运行时与模型，将 enqueue、配置和容器分开管理；代价是修改的接入文件更多，并继续承担 Cubism 2 兼容限制。

以上来自原基础方案的明确比较。大型服装和新运行时是记录中的后续范围，不被改写为已经实现或已完成评估的选项。

## Consequences

依赖、配置和模型目录职责明确，可保留已有 Tia/Pio 资源与 WordPress 缓存机制；代价是旧运行时及模型格式仍限制扩展。素材来源和使用、分发条件尚未补齐，不能用主题 GPL 声明替代第三方许可依据。扩展服装或升级运行时前需要单独确定兼容性和许可依据。

## Verification

本篇于 2026-10-07 核对 `2a42534` 后形成。原生接入及服装范围决定在 2026-09-20 的项目事件中已有记录；本篇日期不表示功能首次提出或首次实现时间。本轮直接核对 enqueue 配置、两种 loader、提示委托及打包排除规则。

历史证据分别覆盖 2026-09-22 的 Tia/Pio WebGL 和模拟 PNG、2026-09-28 的开关/模型切换与冷加载、2026-10-06 的构建74直接实站回归，以及[后续部署边界](../testing/2026-10-07-live2d-deployed-boundaries.md)。原基础计划中的早期保存超时和待部署状态已有后续证据覆盖；本轮不重跑服务器验收，不把各版本结果合并为当前版本全量测试。

历史日期与结果来自维护者核对的原始记录；原始执行资料未随仓库分发。

## Historical deployment evidence

2026-09-23的dev.73/ec55e3f实站确认Pio渲染、提示、拖动保存/刷新、键盘与重置；PJAX文章跳转的performance.timeOrigin不变，模型与播放器各一个。Tia保存请求超时，落库与恢复结果当时未知，工具503不是主题故障；尝试860px却读到1274px也不是有效断点证据。这些状态于2026-09-28重新读取并完成验证后确认设置保存和真实视口，不遗留为当前待办。

2026-09-28原部署实际完成Tia保存及模型/纹理/动作200，随后恢复开启/Pio/远程关闭/接口空并刷新核实；关闭总开关后独立前台无容器、配置和Live2D资源。冷加载实际innerWidth为860时模型请求0，861时可见并正常请求；没有将此前PJAX和全部拖动场景合并宣称重跑。该日站点健康确认PHP8.2.33；build-info中的PHP字段仅表示构建环境。

初始化前点击异常由真实Pio纹理延迟复现：模型对象已创建，命中坐标尚未初始化，普通页面点击也会经全局mousedown进入hitTestSimpleCustom并读取undefined[0]。初始化标记与两组坐标保护修复此路径；测试路由须等待响应处理完成后移除，避免Route is already handled。临时脚本替换验证与之后c7ee80b正式部署分开记录，外部头像/图片超时不能被Live2D监听零异常掩盖。

本节保留已核对的历史结论，原始截图和日志未随仓库分发；远程第三方换装服务、模型许可、大型服装和运行时升级仍未因此完成。

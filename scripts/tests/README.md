# 脚本自测与按需回归

在已安装 PHP 8.0 或更高版本的环境中执行：

```bash
php scripts/validate-commit-messages.php --self-test
php scripts/generate-release-notes.php --self-test
php scripts/theme-metadata.php --self-test
php scripts/compile-translations.php --self-test
```

兼容性CI在PHP矩阵中执行以上四项自测。提交规范与钩子的隔离检查另需Python 3，在CI的提交检查任务中执行：

```bash
python scripts/tests/commit-policy.py php
```

完整 WordPress 检查使用 `wp eval-file scripts/wordpress-smoke.php`。源码契约比较会规范化 CRLF/LF 换行，以兼容 Windows 安装包；实际主题源码和运行时行为不受影响。

核心资源检查使用独立临时目录中的构建信息，覆盖完整源码提交、缺失或无效来源、显式固定版本覆盖、旧本地/远程开关与缓存参数。检查读取真实构建解析函数，完成后恢复过滤器并清理自身夹具，不请求CDN；发布后的远程文件仍需单独核对，见[构建资源引用](../../.agents/notes/implemented/architecture/2026-10-09-core-resource-build-reference.md)。

## 分类图片后台初始化

```bash
php scripts/tests/categories-images.php
```

独立PHP进程加载真实`inc/categories-images.php`，以内存选项和钩子替身覆盖未保存配置、非数组配置、有效/无效排除列表及内置/自定义分类注册。弃用提示等PHP诊断直接使测试失败，并检查初始化不写回选项。无需WordPress、数据库或下载依赖，CI在PHP8.0/8.1/8.2构建矩阵中执行；不替代实站后台心跳与日志验收。

## 前台依赖一致性

```bash
node --test scripts/tests/frontend-vendor.test.cjs
```

检查维护的独立源文件与实际`lib.js`一致且只出现一次，jQuery保持官方完整发行文件，PJAX/Fancybox使用本批版本与已记录的原生API迁移。补丁、生成方式及浏览器验证见[前台迁移笔记](../../.agents/notes/implemented/architecture/2026-10-08-jquery4-and-plugin-migration.md)。这些检查与下方HLS、Live2D测试由CI的前端检查一起运行。

## HLS 加载与播放回归

无需第三方依赖的按需加载、失败重试、并发及过期回调检查：

```bash
node --test scripts/tests/hls.test.cjs
```

测试执行内置APlayer真实`setAudio`，覆盖无HLS封面的音频、后续HLS曲目、显式normal类型、重复初始化、封面销毁和原生回退。媒体元素与网络为隔离模拟；新版库仍须用实际浏览器验证音频/视频解码、worker和站点交互，模拟原生支持不代表Safari验收。已完成范围见[HLS维护笔记](../../.agents/notes/implemented/architecture/2026-10-08-hls-loading-and-upgrade.md)。

## 首页背景视频

```bash
node --test scripts/tests/home-video.test.cjs
```

执行真实首页控制器和PJAX事件接线，覆盖MP4/WebM直连、HLS共享库请求与原生回退、加载失败重试、用户暂停、切换、超时、过期回调和两轮历史恢复。WordPress smoke另验证完整链接与查询参数、专用保存清洗、非法值保留、空列表回退及旧相对目录。状态测试使用模拟媒体，三格式解码与Safari须单独运行浏览器验收；不能把离线接口探针当作播放结果。

## 文章封面编辑

```bash
node --test scripts/tests/post-cover.test.cjs
```

执行完整表单脚本，模拟宿主DOM/store与已解析响应，使用真实Response/FormData验证同页A→B→A、未修改字段保值、保存中继续编辑、旧非法值修正后再校验、请求隔离、失败提示和响应缺失时的保存锁。真实WordPress保存响应HTML解析与经典/区块编辑器交互仍须浏览器验收；该测试已由CI现有`*.test.cjs`入口自动覆盖。

`wordpress-smoke.php`调用`post-cover.php`，在真实WordPress保存钩子中验证文章/页面类型切换、旧值保留、自定义字段并行编辑、nonce与权限、非法输入、修订、缺字段及写入失败提示。测试恢复请求、用户和重定向过滤器，并清理自身草稿；媒体库选图和经典/区块编辑器实际保存需要浏览器验收。选择图片沿用原生特色图片面板，详细契约见[封面编辑器笔记](../../.agents/notes/implemented/feature/2026-10-11-post-cover-editor.md)。

## 登录与前台注册滑块

```bash
node --test scripts/tests/verification.test.cjs
```

执行真实原生脚本，覆盖表单隔离、未完成拦截、完成后隐藏字段不提交、指针取消/释放复位、键盘累积、重复初始化和 PJAX 新表单重置。该测试由现有 CI 的 `*.test.cjs` 自动执行；真实登录、注册及浏览器原生 range 交互仍需单独验收，客户端滑块不替代服务端认证。

## 音乐接口签名

完整 WordPress smoke 自动执行 `music-api.php`：在内存选项和 Transient 过滤器中提供无网络夹具，运行真实 Aplayer 和请求处理器，覆盖四类有效签名、旧 nonce/混合请求拒绝、参数不匹配、过期、服务失败、缓存头及资源签名生成。检查结束恢复过滤器，不写数据库音乐配置。旧 nonce 退役后需刷新旧页面及静态缓存，其他接口的 REST nonce 保持；真实音乐平台播放需另验。

## Live2D 回归

无需第三方依赖的 loader、翻译键、模型资源与换装行为检查：

```bash
node --test scripts/tests/live2d.test.cjs
node --test scripts/tests/live2d-costume.test.cjs
node --test scripts/tests/live2d-catalog.test.cjs
node --test scripts/tests/live2d-managed.test.cjs
php scripts/tests/live2d-managed.php
node scripts/build-live2d-costumes.cjs --check
```

清单单元测试覆盖格式/大小/路径/模型、幂等与过期响应；完整 WordPress smoke 同时执行 `live2d-catalog.php`，验证真实设置保存与读取、非法值保留、脚本依赖、四语言 MO、自定义上传目录示例及旧远程 PNG 模式。测试恢复自身选项与全局状态。

托管库检查覆盖104套生成清单、内置去重、排包规则、默认及旧配置、五来源互斥、模型隔离、固定URL、权限/nonce、256KiB Range/偏移/整文件200回退、失败完整性/续传/并发锁/清除/覆盖与整库激活。PHP独立测试复制现有PNG模拟HTTP流，自动清理临时上传目录；不访问CDN，Windows无符号链接权限时该项跳过、Linux CI执行。真实CDN和目标站点下载仍在发布后验收。

`node scripts/tests/live2d-managed-browser.cjs` 使用同样的Playwright及浏览器路径配置，但不依赖APlayer样式。它运行真实后台脚本，检查来源/模型联动、分片进度、暂停、清除、覆盖与保存隔离，并验证104候选无预取、六次WebGL换装/GPU释放以及Tia隔离。HTTP接口为本地夹具，不能代替生产下载验证。

浏览器回归是按需运行的局部夹具，需要Playwright、Chromium兼容浏览器和与内置运行时一致的APlayer 1.10.1基础样式。除原换装/布局外，使用两个本地 HTTP 源验证同源与跨域清单、PNG缓存、真实10秒/15秒超时和CORS失败恢复；只复用内置纹理，不分发新增素材。克隆仓库不包含这些外部依赖，应在需要复测时准备：

```bash
node scripts/tests/live2d-browser.cjs
```

默认从本地 Node 模块解析 `playwright` 和 `aplayer/dist/APlayer.min.css`。也可通过环境变量 `PLAYWRIGHT_MODULE_PATH`、`APLAYER_CSS_PATH` 指定已有文件，`BROWSER_EXECUTABLE` 指定已安装的 Chromium/Edge；设置 `LIVE2D_SCREENSHOT_DIR` 可保存模型截图。

这些依赖和测试截图不属于主题构建输入。正式测试源码继续维护，测试结束后按需保留结论和必要附件；既有临时WordPress、下载包或测试CSS无需长期保留。项目效果验收使用GitHub构建包更新测试服务器，局部夹具结果不替代该验收。

测试启动仅监听 `127.0.0.1` 的临时 HTTP 服务，使用仓库中的 Live2D、jQuery、APlayer 和播放器初始化代码，覆盖布局、鼠标/触摸拖动、位置恢复、键盘、重置、提示、860/861px 边界及 WebGL 渲染。远程换装使用本地 PNG 模拟接口响应，不访问第三方换装服务。

固定页头和 WordPress 管理栏夹具验证边缘位置的控件仍可点击，包含历史位置恢复、拖动、键盘、缩放和页头移出屏幕后返回。延迟纹理响应的用例验证模型初始化前点击不会抛异常，随后仍能正常渲染。内置 Cubism 2 的 `hitTestSimpleCustom` 保留就绪状态及坐标数组保护；替换运行时时应保留该修复或确认上游已解决，并运行此回归。

换装单测覆盖就绪和单套守卫、本地不立即重复、忙碌时阻止重复请求、图片错误/超时/上传失败回收、模型变化与远程查询参数。浏览器执行Tia/Pio各六次切换、键盘Enter、404恢复及模拟远程PNG两次切换，检查模型和Canvas保持单实例、旧GPU纹理释放、没有模型/动作重载。缓存命中不要求额外网络请求；本地模拟接口不能证明第三方返回不同衣服或具备CORS。实现边界见[换装笔记](../../.agents/notes/implemented/feature/2026-10-17-live2d-costume-button.md)。

播放器收起回归保留真实歌词显示，不手动隐藏歌词或强制重算；覆盖默认/自定义位置恢复、歌词窗口外与空白文本、实际文字重叠，以及歌词显示和播放进度事件驱动的避让更新。

该夹具额外提供 APlayer 1.10.1 参考基础样式，并不验证 WordPress 整站的样式加载、后台设置、权限或构建矩阵；这些仍须在实际 WordPress 环境验收。

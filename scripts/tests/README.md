# 发布脚本自测

在已安装 PHP 8.0 或更高版本的环境中执行：

```bash
php scripts/validate-commit-messages.php --self-test
php scripts/generate-release-notes.php --self-test
```

CI 会在 PHP 矩阵中执行这两项自测。

完整 WordPress 检查使用 `wp eval-file scripts/wordpress-smoke.php`。源码契约比较会规范化 CRLF/LF 换行，以兼容 Windows 安装包；实际主题源码和运行时行为不受影响。

## Live2D 回归

无需第三方依赖的 loader、翻译键和模型资源检查：

```bash
node --test scripts/tests/live2d.test.cjs
```

浏览器回归使用 Playwright Chromium 和与内置运行时一致的 APlayer 1.10.1 基础样式：

```bash
node scripts/tests/live2d-browser.cjs
```

默认从本地 Node 模块解析 `playwright` 和 `aplayer/dist/APlayer.min.css`。也可通过环境变量 `PLAYWRIGHT_MODULE_PATH`、`APLAYER_CSS_PATH` 指定已有文件，`BROWSER_EXECUTABLE` 指定已安装的 Chromium/Edge；设置 `LIVE2D_SCREENSHOT_DIR` 可保存模型截图。

测试启动仅监听 `127.0.0.1` 的临时 HTTP 服务，使用仓库中的 Live2D、jQuery、APlayer 和播放器初始化代码，覆盖布局、鼠标/触摸拖动、位置恢复、键盘、重置、提示、860/861px 边界及 WebGL 渲染。远程换装使用本地 PNG 模拟接口响应，不访问第三方换装服务。

固定页头和 WordPress 管理栏夹具验证边缘位置的控件仍可点击，包含历史位置恢复、拖动、键盘、缩放和页头移出屏幕后返回。延迟纹理响应的用例验证模型初始化前点击不会抛异常，随后仍能正常渲染。内置 Cubism 2 的 `hitTestSimpleCustom` 保留就绪状态及坐标数组保护；替换运行时时应保留该修复或确认上游已解决，并运行此回归。

播放器收起回归保留真实歌词显示，不手动隐藏歌词或强制重算；覆盖默认/自定义位置恢复、歌词窗口外与空白文本、实际文字重叠，以及歌词显示和播放进度事件驱动的避让更新。

该夹具额外提供 APlayer 1.10.1 参考基础样式，并不验证 WordPress 整站的样式加载、后台设置、权限或构建矩阵；这些仍须在实际 WordPress 环境验收。

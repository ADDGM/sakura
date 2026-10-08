# Agent Note: jQuery 4 与前台插件迁移

Status: implemented

## Problem

前台实际加载[lib.js](../../../../cdn/js/lib.js)，其中jQuery为3.5.1，PJAX与Fancybox仍使用jQuery 4已移除的类型、数组、函数、数字和trim工具。只替换独立jQuery文件既不会更新实际页面，也不能解决插件报错；永久恢复旧API会让旧依赖继续隐藏在迁移层后面。

## Decision

维护者于2026-10-08批准分批升级，本篇持有第二批实现。前台使用jQuery完整UMD 4.0.0、jquery-pjax 2.0.1、当前GPL发行包`@fancyapps/fancybox`的最新3.5.7。APlayer保持最新1.10.1，HLS首批结果见[HLS实现](2026-10-08-hls-loading-and-upgrade.md)。

1. 固定npm发行包经SHA-512 integrity核验。jQuery为官方完整UMD，保留Ajax、Deferred等必需模块；独立文件和实际合并包同步，附带MIT许可。
2. PJAX/Fancybox的已删除jQuery调用改为原生类型判断、Array.isArray和字符串trim。Fancybox的DOM/jQuery内容对象与普通参数对象分别判断；动画时间接受有限数字及数字字符串。`jQuery`对象上不恢复旧方法，不引入Migrate。[固定源补丁脚本](../../../../scripts/vendor/jquery4.cjs)保留每项修改，并拒绝哈希或预期匹配数量不同的上游源码。
3. [主题脚本](../../../../js/sakura-app.js)的QQ字段在读取时统一trim，不再调用`$.trim`。合并包只替换三个已核对的唯一组件片段，屏蔽这些片段后其余字节保持完全一致。Fancybox独立CSS已与3.5.7官方文件逐字节相同，因此本批没有更换CSS。
4. [依赖一致性检查](../../../../scripts/tests/frontend-vendor.test.cjs)验证实际合并包与四个受影响/关联的独立文件同步且无重复副本。现有CI的前端步骤执行全部`*.test.cjs`；Actions固定方式与现存文件的安全扫描范围不变。删除后台Iris时仅移除该不存在文件的扫描豁免，见[后台依赖](2026-10-07-admin-settings-boundary.md#native-color-picker-dependencies)。

## Reproducible vendor patch

原始发行来源：[jquery 4.0.0](https://registry.npmjs.org/jquery/-/jquery-4.0.0.tgz)、[jquery-pjax 2.0.1](https://registry.npmjs.org/jquery-pjax/-/jquery-pjax-2.0.1.tgz)、[Fancybox 3.5.7](https://registry.npmjs.org/@fancyapps/fancybox/-/fancybox-3.5.7.tgz)。上游PJAX `jquery.pjax.js` SHA-256为`2e82459381db588f242c24defc00aab89d22211f6f8512ee1b9cd57bf3df8cce`；Fancybox `dist/jquery.fancybox.js`为`d1c11df54787c676de783bcca52618b14e2812a587da3f8e0f79a8a0e4d2597b`。先核验发行包integrity，再将原始文件传给补丁脚本：

```sh
node "scripts/vendor/jquery4.cjs" "<上游>/jquery.pjax.js" "<上游>/jquery.fancybox.js" "<输出目录>"
npm exec --yes --package="terser@5.51.2" -- terser "<输出目录>/jquery.pjax.js" --compress --mangle --comments "/^!|Licensed|Copyright/" --output "cdn/js/src/02.jquery.pjax.min.js"
npm exec --yes --package="terser@5.51.2" -- terser "<输出目录>/jquery.fancybox.js" --compress --mangle --comments "/^!|Licensed|Copyright/" --output "cdn/js/src/17.query.fancybox.min.js"
```

这只生成独立文件；合并包对应片段仍须同步，再运行一致性检查。替换文本必须通过函数返回原始字符串，不能让JavaScript的`String.replace`把第三方源码中的美元符号序列当替换语法。本轮首次内存组装被片段外一致性检查拦住，修正回调替换后才写入文件。

| 分发文件 | SHA-256 |
| --- | --- |
| `01.jquery.min.js`（官方原字节） | `39a546ea9ad97f8bfaf5d3e0e8f8556adb415e470e59007ada9759dce472adaa` |
| `02.jquery.pjax.min.js`（补丁后） | `64300dece57e688e7c18f3adf3c5404f14e2e91a437f7713d292a0799c58f670` |
| `17.query.fancybox.min.js`（补丁后） | `a1e367e0ded63c75c2351c08da669e29e49c3a9657a4f105063eeec5631959ba` |

MIT与GPLv3许可分别随独立文件保留。Fancybox的[GPLv3全文](../../../../cdn/js/src/17.query.fancybox.LICENSE.txt)为FSF许可文本，SHA-256 `3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986`。GPL文本不把后继商业产品纳入授权。

## Version and license boundary

2026-10-08 npm latest核对：`jquery` 4.0.0、`jquery-pjax` 2.0.1、`@fancyapps/fancybox` 3.5.7。后继独立包`@fancyapps/ui`为6.1.15，其LICENSE.md要求接受Fancyapps UI专有许可，并指向定价页，不能将它当成GPL插件的无条件替换。当前主题声明GPL v2 or later，本批采用原GPL插件发行线的最新版本，不引入商业授权或改用另一套灯箱接口。

[jQuery 4迁移指南](https://jquery.com/upgrade-guide/4.0/)同时涉及脚本请求、CSS数字单位、focus事件及选择器变化，因此消除静态API命中只是第一步。浏览器原生能力检测、插件公共参数及WordPress宿主接口不是旧版库副本，仍按实际功能维护。

## Existing record audit

已检索所有活跃项目笔记。与[核心资源来源](../../implemented/architecture/2026-10-07-core-resource-policy.md)、[HLS加载](../../implemented/architecture/2026-10-08-hls-loading-and-upgrade.md)部分重叠，分别保留资源地址和播放器时序；[评论初始化](../../implemented/bug-fix/2026-10-07-comment-form-initialization.md)保留表单结构与事件幂等规则。[后台设置](../../implemented/architecture/2026-10-07-admin-settings-boundary.md)持有宿主后台依赖，不随前台替换jQuery。没有已有同主题决定需要归档。

## Alternatives considered

- **停留jQuery 3.7.1**：迁移量小，旧插件可沿用；维护者已选择以最新稳定版为目标，因此不作为本批交付终点。
- **jQuery 4加永久Migrate或全局旧API补丁**：可快速恢复第三方插件行为，适合短期诊断；会掩盖未迁移调用，本批直接修改消费者并用测试证明新接口可用。
- **迁移到Fancyapps UI 6**：后继产品持续维护、接口更现代；专有许可与现有GPL发行方式不同，也需要替换灯箱调用契约，本批不接受额外协议或引入商业依赖。
- **最新GPL插件加局部原生API迁移（采用）**：保持当前功能与许可，直接消除已删除接口调用；代价是维护有限的上游补丁，并需真实浏览器验证行为。

## Consequences

前台采用最新版jQuery并直接迁移消费者，避免把旧接口恢复成长期依赖。代价是插件上游尚未完成jQuery 4迁移，局部补丁需要在下次升级时重审。Fancybox 3.5.7是原包最新版本，不等于后继商业产品，也不承诺历史插件的所有配置组合都已测试。此次OSV直接版本查询三包均无匹配，只是已知公告检索，不代表完整供应链或站点安全证明。

本批更换的是安装包内资源。2026-10-08实现时，[核心资源契约](2026-10-07-core-resource-policy.md)的远程默认标签v3.5.0尚不包含本批代码；2026-10-09的[构建资源引用](2026-10-09-core-resource-build-reference.md)已改为随安装包源码提交固定地址。使用远程核心资源的发布验收仍需核对该提交的实际文件，不能仅凭本地测试宣布CDN已经升级。

## Verification

2026-10-08，6项依赖一致性检查与35项HLS/Live2D测试通过。Chromium 154加载实际合并包，确认jQuery 4.0.0、Fancybox 3.5.7，`isFunction/type/isNumeric/isArray/trim/migrateVersion`均不存在。浏览器夹具通过PJAX点击与标题trim、history恢复、函数URL与数组参数；Fancybox HTML、图片切换、caption/命令回调、数字字符串动画时间和关闭；QQ带空白输入自动填写。上述六组无pageerror。

jQuery 4下重验HLS并发音频/视频：1.7.3只请求一次，worker启动，播放时间前进，视频解码宽度1280，无pageerror。此前普通音频、后续HLS曲目等证据及Safari边界保留在HLS笔记。

测试站前台通过浏览器临时响应替换加载本地`lib.js`与`js/sakura-app.js`，音乐歌单使用本地夹具响应；文章跳转和返回的`performance.timeOrigin`不变，播放器、Live2D容器、Canvas及上传控件各一个，没有pageerror或Migrate。此检查验证真实WordPress页面上的前端集成，不修改服务器文件、提交评论或设置，也不代替构建包部署、真实音乐平台及完整浏览器矩阵验收。

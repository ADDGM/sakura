# Sakura🌸: 樱花庄的白猫博客主题

中文 | [English](README-en.md)

![Sakura](screenshot.jpg)

![PHP version](https://img.shields.io/badge/PHP-8.0--8.2-4F5B93.svg?style=flat-square&logo=php)
![WP version](https://img.shields.io/badge/WordPress-7.0--7.1-0073aa.svg?style=flat-square&logo=wordpress)
[![GitHub release](https://img.shields.io/github/v/release/ADDGM/sakura.svg?style=flat-square&logo=github)](https://github.com/ADDGM/sakura/releases/latest)
[![Github commits (since latest release)](https://img.shields.io/github/commits-since/ADDGM/sakura/latest/develop.svg?style=flat-square&logo=git&color=important)](https://github.com/ADDGM/sakura/commits/develop)
[![](https://data.jsdelivr.com/v1/package/gh/moezx/cdn/badge)](https://www.jsdelivr.com/package/gh/moezx/cdn)

在 Louie 基于 Fuzzz 的 [Akina](http://www.akina.pw/themeakina) 主题修改的主题 [Siren](https://github.com/louie-senpai/Siren) 基础上三次修改 =.=

两位前辈做得已经很棒了，或许我所做的只是把他们的代码弄得凌乱不堪吧 :)

特别感谢 [@Spirit](https://github.com/spirit1431007) 对本项目的贡献！

注意：建议 `git clone` 下载（[简易 Git 使用指南](https://github.com/mashirozx/Sakura/wiki/Git-%E4%B8%8B%E8%BD%BD%E3%80%81%E6%9B%B4%E6%96%B0%E6%8C%87%E5%8D%97)）；如果选择下载压缩包，**解压后记得把文件夹名改回 `Sakura`，也即保证主题路径为 `/wp-content/themes/Sakura/`**；主题设置在 `菜单-外观-Sakura 主题设置` 中；DIY 的时候建议采用[子主题](https://github.com/mashirozx/Sakura/tree/child) 并勾选 `Sakura 主题设置-CDN-本地调用主题 js、css 文件`；请留意主题说明里的其他注意事项。

主题使用说明见：<https://2heng.xin/theme-sakura/>

### 当前维护版本

`develop` 分支用于兼容性升级，目标环境为 WordPress 7.0/7.1 与 PHP 8.0/8.1/8.2。推送到 `develop` 后，GitHub Actions 会生成可下载的测试主题包；发布版本时会根据中文 Git 提交自动生成中文更新记录。

主题头中的兼容性字段含义如下：

- `Requires at least: 7.0` 是允许安装的最低 WordPress 版本，不是唯一支持的版本。
- `Tested up to: 7.1` 表示本维护版本已完成测试的最高 WordPress 版本。
- `Requires PHP: 8.0` 是允许安装的最低 PHP 版本；当前 CI 会验证 PHP 8.0、8.1 和 8.2。
- Release 中的主题 ZIP 是通用安装包，不按 WordPress 或 PHP 版本分别构建；只要运行环境满足最低版本并处于已验证矩阵内，即可使用同一个包。

CI 会从 `style.css` 自动读取源码基准版本，并使用 `<源码版本>-dev.<运行编号>` 作为测试包版本。Release 包使用去掉 `v` 前缀后的标签版本，例如标签 `v3.5.0-beta.2` 对应主题版本 `3.5.0-beta.2`；发布前会校验标签核心版本与源码基准版本一致。

WordPress 上传主题后会读取并显示完整版本字符串，因此测试包会显示类似 `3.5.0-dev.123`，预发布包会显示 `3.5.0-beta.2`。WordPress 通常不会额外显示“测试版”徽标，需要通过版本号中的 `dev` 或 `beta` 判断；正式版本则显示为 `3.5.0`。

### 构建包、测试包与发布包

CI 会在每次推送 `develop` 后使用 PHP 8.0、8.1、8.2 分别执行同一套检查，因此同一运行编号会出现三个 Actions Artifact：

```text
sakura-ci-php8.0-19
sakura-ci-php8.1-19
sakura-ci-php8.2-19
```

这三个名称表示“该包由哪个 PHP 矩阵任务验证”，不是三个功能不同或只能运行在对应 PHP 版本上的主题。主题是 PHP 源代码，不包含按 PHP 版本编译的二进制文件；三个任务生成的主题内容原则上相同，区别主要是 `build-info.txt` 中记录的验证环境和 Artifact 名称。

如果测试服务器使用 PHP 8.1，可以优先下载 `sakura-ci-php8.1-19`，这样下载记录和服务器环境一致；使用另外两个 CI 包通常也可以安装，但不能把它们理解成“PHP 8.0 专用包”或“PHP 8.2 专用包”。

下载 GitHub Actions Artifact 后，还会再得到一个外层下载压缩包。部署时应解压外层 Artifact，再把里面的主题 ZIP 上传到 WordPress：

```text
Actions Artifact（外层下载压缩包）
└─ sakura-ci-19.zip                 ← 上传这个主题 ZIP
   └─ sakura/style.css
```

CI Artifact 只用于快速验证最新 `develop` 提交，当前保留 7 天，不作为长期下载地址。

Release 工作流也会上传一个 Actions Artifact，名称为 `sakura-release-bundle-X.Y.Z` 或 `sakura-release-bundle-X.Y.Z-beta.N`。它同样是外层资料包，不能直接上传 WordPress；下载后请先解压，再上传其中的 `sakura-X.Y.Z.zip` 或 `sakura-X.Y.Z-beta.N.zip`。Release 页面中的版本主题 ZIP（例如 `sakura-3.5.0-beta.5.zip`）则是可直接安装的包：

```text
Actions Artifact（外层发布资料包）
└─ sakura-release-bundle-3.5.0-beta.5.zip  ← 下载后得到的外层 ZIP
   ├─ sakura-3.5.0-beta.5.zip              ← 上传这个主题 ZIP
   │  └─ sakura/style.css
   ├─ sakura-3.5.0-beta.5.zip.sha256
   └─ release-notes.zh-CN.md
```

推送版本标签后，Release 工作流只构建一个主题 ZIP，例如：

```text
sakura-3.5.0-beta.4.zip
sakura-3.5.0-beta.4.zip.sha256
release-notes.zh-CN.md
```

标签包只有一个，是因为 Release 发布的是同一份通用主题源代码；PHP/WordPress 矩阵的职责是验证兼容性，不是为每个环境制作不同安装包。Release 包由 PHP 8.2 的打包任务生成，但仍适用于已验证的 WordPress 7.0/7.1 与 PHP 8.0/8.1/8.2 环境。正式标签 `v3.5.0` 与预发布标签 `v3.5.0-beta.4` 的 ZIP 结构相同，区别在于版本号和 Release 是否标记为预发布。

各类文件的使用建议如下：

| 文件或包 | 适用场景 | 是否建议上传 WordPress |
| --- | --- | --- |
| `sakura-ci-phpX.Y-N` | 验证某次 `develop` 提交，优先选择与测试服务器 PHP 相同的矩阵包 | 解压外层后，上传内部 `sakura-ci-N.zip` |
| `sakura-release-bundle-X.Y.Z[-beta.N]` | 查看标签发布工作流生成的完整资料 | 解压外层后，上传内部版本主题 ZIP |
| `sakura-X.Y.Z-beta.N.zip` | 测试服务器、预发布环境和候选版本验收 | 是 |
| `sakura-X.Y.Z.zip` | 正式生产环境 | 是 |
| GitHub `Download ZIP` 源码包 | 浏览源码或临时开发 | 不建议，优先使用 Release 主题 ZIP |
| `*.sha256` | 下载后校验主题 ZIP 是否完整 | 不上传 |
| `release-notes.zh-CN.md` | 查看本次中文更新记录 | 不上传 |

Release 工作流的 `workflow_dispatch` 仅用于重建已有标签的产物、更新说明或覆盖同名资产，不会自动产生新的版本号；需要新版本时应创建新的中文标签。

本维护版本的作者标记为 `ADDGM`，主题说明中保留原作者 Mashiro、Spirit、Louie、Fuzzz 2heng 的贡献信息。上传 ZIP 后，WordPress 会从压缩包内 `sakura/style.css` 的主题头读取名称、版本和兼容性信息。

### 内置素材说明

`images/dash-sakura-bg.webp` 为 Sakura 后台配色方案的预设背景图，来自上游 Sakura 主题原先通过 `view.moezx.cc` 外链引用的同一张图片，现内置到主题以摆脱对外部域名的依赖，版权归原作者所有。该图已由原始 PNG（223 KB）转为 WebP（18 KB）；不支持 WebP 的浏览器会退化为纯色后台背景，不影响任何功能。

后台配色「Custom」方案会自动加载恢复后的旧版规则，并使用主题内置的 `images/Custom.jpg` 作为后台背景，不依赖外部图片服务。「Custom 后台附加样式（CSS）」只用于追加或覆盖这些源码默认规则；留空或只保留注释即可恢复源码默认效果，点击「重置为默认」也不会关闭内置规则。已保存的旧版死链规则或注释示例会在渲染时视为空附加值，不修改数据库。

维护版本的提交格式为：

```text
类型(范围): 中文摘要
```

类型使用 `新增`、`修复`、`兼容`、`优化`、`重构`、`文档`、`构建`、`测试` 或 `发布`。

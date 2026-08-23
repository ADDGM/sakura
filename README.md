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

本维护版本的作者标记为 `ADDGM`，主题说明中保留原作者 Mashiro、Spirit、Louie、Fuzzz 2heng 的贡献信息。上传 ZIP 后，WordPress 会从压缩包内 `sakura/style.css` 的主题头读取名称、版本和兼容性信息。

维护版本的提交格式为：

```text
类型(范围): 中文摘要
```

类型使用 `新增`、`修复`、`兼容`、`优化`、`重构`、`文档`、`构建`、`测试` 或 `发布`。

# 外置服装清单

主题自带 Tia、Pio 的基础服装。扩展 PNG 与 JSON 清单可以放在站点上传目录或已有静态 CDN，不随主题 ZIP 分发；主题更新后无需重新上传服装。

## 部署

1. 在「主题设置 → 看板娘」选择模型，关闭「使用远程 Live2D 服装 API」。旧选项仍用于随机 PNG 接口，不能填写 JSON。
2. 按「外置 Live2D 服装清单」下显示的实际目录，通过 SFTP 或主机文件管理器上传文件。自定义上传目录或多站点以后台显示的路径为准。

   ```text
   uploads/sakura-live2d/pio/pack-v1/
     catalog.json
     textures/
       outfit-a.png
       outfit-b.png
   ```

3. 按下方格式创建清单，填写完整清单 URL 并保存。在大于等于 861px 的桌面窗口刷新前台，使用原来的「换装」按钮。
4. 更新服装时上传到新版本目录（例如 `pack-v2`），同步 `packVersion`，验收后修改清单 URL。旧目录待旧页面与缓存不再使用后自行清理；将服装目录纳入备份。

```json
{
  "version": 1,
  "packVersion": "v1",
  "model": {
    "id": "pio",
    "sha256": "1545fdb296bb2d1c9c6f1c295b3a336705618aa467c45e9be8dbc9cb1ffb704d"
  },
  "costumes": [
    {"id": "outfit-a", "texture": "textures/outfit-a.png"},
    {"id": "outfit-b", "texture": "textures/outfit-b.png"}
  ]
}
```

示例 PNG 文件名是占位名，需替换为你已准备好且适配该模型的图片。清单只声明模型兼容性，主题不会凭图片内容证明其适配性或来源。Tia 与 Pio 的纹理不能混用。

| 模型 | 当前 `model.moc` 的 SHA-256 |
| --- | --- |
| Pio | `1545fdb296bb2d1c9c6f1c295b3a336705618aa467c45e9be8dbc9cb1ffb704d` |
| Tia | `f19c7a20bf7eafcc1ef303b9c9c52d0face97aa0990129aeddec8d5e089d1ef5` |

更换模型文件后须重新检查兼容性并更新清单指纹；前台使用安装中的模型文件计算出的指纹校验。

## 格式与访问要求

- 清单为 UTF-8 JSON，格式 `version` 固定为数字 `1`，最大 64 KiB、256 项。包版本和服装 ID 为 1–64 个英文字母、数字、点、下划线或短横线，首字符为字母或数字。
- `texture` 为清单所在目录下的相对 PNG 路径，最长 240 字符。每段只用英文字母、数字、点、下划线或短横线，首字符不能为点。不接受绝对路径、`..` 路径段、反斜杠、百分号编码、查询参数或片段。
- 重复路径/相同条目只加入一次；同一 ID 指向不同路径、任何非法条目、未知格式或模型不匹配，整份清单不接入。空清单保持内置服装。
- 清单地址须为完整 HTTP(S) URL，路径以 `.json` 结尾，可有稳定查询参数，不含账号密码或片段；直接返回 JSON，不使用重定向。HTTPS 页面须使用 HTTPS 资源。
- CDN 的 JSON **和 PNG** 都需提供允许本站读取的 CORS 响应头，例如公开静态资源使用 `Access-Control-Allow-Origin: *`。图片能在地址栏打开不代表可用于 WebGL。遵循本站 CSP 的 `connect-src` 和 `img-src` 配置。
- 给版本化文件设置适当的 `Cache-Control`；更改内容时换目录/URL。`packVersion` 用于标识包，不会自动给图片加随机参数，也不自动刷新旧缓存。CORS 修正后若旧响应仍被缓存，应清理缓存或更换版本 URL。

## 运行与故障处理

先显示内置模型，后台读取清单，首次显示不会预下载扩展 PNG。每次换装只加载选中图片，已经访问的固定 URL 可由浏览器缓存。清单请求超过 10 秒、404、格式/CORS 错误时继续使用内置服装；图片超过 15 秒或加载失败时保留当前纹理，可再次换装重试。图片尺寸不得超过设备的 WebGL 纹理上限。

保存非法地址会提示错误并保留原值；保存时不探测网络，因此仍需前台验收地址是否可访问。清单失败后可刷新页面重试。留空清单配置即可停用扩展服装，文件由部署维护者保管，主题不会下载、写入或删除它们。

当前不包含后台下载器、ZIP 导入器或资源商城，也不自动分发仓库根级的额外服装候选。

## 关于主题上传大小

自托管 WordPress 没有各站通用的“50 MB主题上限”。[后台上传值](https://developer.wordpress.org/reference/functions/wp_max_upload_size/)受 PHP 的 `upload_max_filesize`、`post_max_size` 和站点过滤器影响，Web 服务器及托管平台还可能限制请求体。后台「媒体 → 添加」可查看 WordPress 显示值；主题 ZIP 上传是否成功还取决于服务器实际配置。

WordPress.org 主题目录的投稿是另一个入口：截至 2026-10-17，[官方上传源码](https://github.com/WordPress/wordpress.org/blob/trunk/wordpress.org/public_html/wp-content/plugins/theme-directory/upload.php)设置的是 `10 * MB_IN_BYTES`，不能据此推定个人站点的上限。

把图片单独存放能让主题 ZIP 大小不随服装数量增加。通过同一后台上传入口传独立 ZIP 仍受上传限制；SFTP/主机文件管理器是否有额外限制，取决于服务商。

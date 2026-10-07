# Agent Note: 评论登录态结构与 PJAX 上传控件幂等性

Status: implemented

## Problem

WordPress登录后省略匿名字段，旧主题却依赖这些字段闭合容器，导致头像与提交按钮重叠；普通初始化和PJAX再次插入上传节点，又可能造成重复事件与重复请求。

## Decision

[comments.php](../../../../comments.php)仅为匿名用户添加头像与隐藏QQ字段，随同这一组闭合容器；不依赖登录态会省略的url字段闭合。保留comment_form的字段名、提交参数和提示语义。

[js/sakura-app.js](../../../../js/sakura-app.js)的 add_upload_tips()在当前首个提交区复用文件输入与提示节点、删除该区域的重复节点；可见按钮使用label关联文件输入。attach_image()采用document上的change.sakuraUpload命名空间委托，重绑前解除同名处理器，允许PJAX替换表单后再次初始化。

提交按钮使用专用submit-comment-tips容器及提示样式；通用popup不能控制它的display布局。上传选择与主题既有接口保持原契约；本篇不将前端文件数量/大小限制视为服务端安全校验。

## Existing record audit

活跃笔记未覆盖评论表单初始化；三个评论修复计划按同一表单边界提炼。[兼容性验证](../testing/2026-10-07-compatibility-evidence.md)持有登录态和视口验证层级，[后台设置](../architecture/2026-10-07-admin-settings-boundary.md)承接原混合计划中的radio部分，本篇不复制它们。

## Alternatives considered

- 复用原动态字段拼接：结构变化少，但登录时字段省略会改变容器平衡；当前按登录状态明确组织匿名字段。
- 用透明文件控件位移覆盖上传按钮：旧布局可触发选择，但点击区域与提交区互相覆盖；改为label和明确布局。
- 提交提示继续使用通用popup类：可复用样式，但历史已证实后加载的inline-block覆盖flex；采用专用类。原记录未保留命名为“方案B”的完整正文，不补造。

## Consequences

重复初始化可重用当前控件，提交与选择图片的命中区域分离；代价是仍需维护旧jQuery/PJAX及comment_form扩展。这是当前单评论表单的实现，不代表任意多个表单的全页面幂等保证，也不证明第三方上传API可用。

## Verification

2026-10-07按 `2a42534` 核对匿名字段分组、专用提交容器、节点复用与事件委托。Beta.4记录有登录/匿名、桌面/390px和按钮区域复验；实际图床上传当时未测，仍需另行验证。本轮没有提交评论或上传文件，成文日不推定旧修复的首次提出日。

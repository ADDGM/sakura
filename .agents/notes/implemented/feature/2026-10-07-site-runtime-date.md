# Agent Note: 建站日期的保存校验与自然日语义

Status: implemented

## Problem

建站时间为空、格式含糊或位于未来时，页脚可能显示错误天数。只增加清理函数而未接入 text 字段的保存路径，曾让未来日期绕过校验。

## Decision

继续使用 `site_start_date` 文本字段，默认空值；placeholder 只是格式示例，不是默认日期。[sakura_parse_site_start_date](../../../../functions.php) 严格检查 YYYY-MM-DD、真实日历日期及不晚于网站今天，采用 WordPress 时区；缺少相关 WordPress 时间函数时回退 UTC。

[of_sanitize_site_start_date](../../../../inc/options-sanitize.php) 接受空值以关闭显示；非法非空输入提示错误并返回已有保存值。渲染再次校验，非法历史值不显示天数。[optionsframework_validate](../../../../inc/options-framework.php) 和默认值处理优先按字段 ID 查找清理器，再回退类型清理器，防止 text 通用清理掩盖日期规则。

`sakura_get_site_runtime_days()` 计算两日期的自然日差；[footer.php](../../../../footer.php) 输出合法结果，建站当天为0。不按浏览器时区或经过的秒数除以86400计算，避免网站时区和夏令时边界产生歧义。

## Existing record audit

活跃笔记无建站日期决定。[后台设置边界](../architecture/2026-10-07-admin-settings-boundary.md)部分重叠，持有通用渲染和存储约定，本篇持有字段校验。原建站时间与邮件混合计划拆分为本篇和[测试邮件](2026-10-07-test-mail-boundary.md)。

## Alternatives considered

- 文本输入加服务端校验（采用）：沿用现有框架，同时明确格式和错误回退。
- 独立 HTML5 date 字段：提供浏览器日期选择能力，但原计划明确不扩展新字段类型；保留文本方案。历史没有更完整的浏览器比选记录。
- placeholder扩展若不兼容，原计划允许退回普通空文本框加说明；这是回退方案，不表示删除合法保存值或关闭服务端校验。

## Consequences

保存和显示共用日期语义，空值可关闭；代价是用户需要按指定格式输入。站点时区变化可能改变“今天”及显示天数，这是网站时间语义的结果。保存失败回退的旧值仍由渲染端校验，不能断言历史数据都已清洗。

## Verification

2026-10-07按 `2a42534` 核对日期辅助函数、专用清理器及字段ID优先分派；现有 smoke 有闰日、非法日期、未来日期和保存回退检查。原Beta.5/Beta.6记录有日期保存、空值及未来值回归，本轮只复核源码和记录，没有修改站点日期或时区。成文日期不代表原功能首次提出日。

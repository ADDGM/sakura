# Agent Note: 远程核心资源跟随安装包构建来源

Status: implemented

## Problem

主题版本已为3.7.0，远程核心资源仍固定在v3.5.0。安装包内的jQuery、PJAX、Fancybox和HLS升级后，开启远程资源的用户仍可能取到旧的前台代码。仅把常量改成v3.7.0也不能包含尚未发布的升级；后续每次发布都手工同步第二个版本号容易再次遗漏。

## Decision

保留核心资源本地默认和两个已保存开关的语义。[functions.php](../../../../functions.php)的`sakura_remote_resource_ref()`复用`inc/release-info.php`的`sakura_release_build_info()`，用现有构建元数据中的完整40位源码提交号固定jsDelivr地址；正式包和开发构建均使用自身的源码提交，不推导最新Release、主题Version或可变分支。

没有可用完整提交号时使用包内资源，不猜测本地未提交内容对应哪个远程版本。保留`SAKURA_REMOTE_RESOURCE_TAG`显式配置及`sakura_remote_resource_tag`过滤器入口，接受完整提交号或`vX.Y.Z`、Beta、RC固定标签；无效值回到本次构建提交，没有构建提交则使用本地。显式覆盖者负责确认目标文件与当前安装内容一致。

这一步仅改变地址解析，不发起版本查询，不处理CDN网络失败，不更改数据库选项、主题版本或已发布的Git标签。现有构建和发布流程均已写入`source_sha`，不增加另一份清单或发布步骤。

## Existing record audit

[核心资源策略](../../implemented/architecture/2026-10-07-core-resource-policy.md)与本篇部分重叠：继续约束本地默认、旧选项语义、缓存和网络边界；本篇接管手工固定远程标签的默认来源选择，保留旧方案的理由。[前台依赖迁移](../../implemented/architecture/2026-10-08-jquery4-and-plugin-migration.md)与[HLS升级](../../implemented/architecture/2026-10-08-hls-loading-and-upgrade.md)持有库迁移和浏览器证据，不由本次解析检查重新证明。v3.7.0的历史正式交付仍由[发布笔记](../../implemented/process/2026-10-07-stable-release.md)持有。

## Alternatives considered

- **每次发布手工更新标签**：修改最少且保留容易辨认的版本地址，但必须维护两处同步状态，当前遗漏已说明其风险；改到既有v3.7.0也不能交付本轮未发布代码。
- **使用主题Version推导标签**：不需要额外元数据，对正式标签直观；本地源码仍写3.7.0，开发包还可能是没有Git标签的dev版本，无法据此保证资源对应当前源码。
- **使用构建源码提交（采用）**：现有正式包和开发包已提供该字段，引用明确且不用查询远端。没有构建来源的原始源码包需要使用本地资源，或由维护者显式提供核对过的固定引用。

## Verification

2026-10-09，[WordPress smoke](../../../../scripts/wordpress-smoke.php)在WordPress7.0、7.1分别搭配PHP8.0.30、8.1.34、8.2.33的六组Windows/SQLite环境全部通过，没有新增WP_DEBUG日志。两份修改PHP文件在三个运行时的语法检查通过。这里复用既有隔离站点，更新本次修改文件后执行完整smoke；不把前一天的安装包哈希描述为本次源码的哈希。

资源回归包含23组构建信息/覆盖值、5类本地或远程选项、4条核心JS/CSS路径及带/不带缓存参数，合计920次URL断言，另检查缓存版本拼接。夹具通过临时`template_directory`过滤器读取实际`build-info.txt`，不替换构建解析函数；缺失来源、短SHA、非法覆盖回退、显式Beta/RC与完整SHA均被覆盖。`finally`恢复所有临时过滤器，并仅清理自身创建的夹具文件和目录。

在隔离副本中将默认引用改回v3.5.0、放宽校验接受浮动分支，分别产生456和144条预期的资源断言失败；无其他错误或新增debug日志。恢复文件字节后完整smoke再通过。另用四个独立WP-CLI进程验证显式常量的固定标签、完整SHA、非法分支与数组值，均通过。

Semgrep1.178.0使用显式`p/php`规则集、关闭metrics，仅扫描本次修改的`functions.php`和`scripts/wordpress-smoke.php`，结果为0条报告、0项扫描错误；不代替既有全库扫描或清除其未处理提示。

本次本地验证包按当前工作副本及`.gitattributes`重新生成，397个文件与源码逐字节一致、CRC通过，大小15,701,148字节，SHA-256为`34ed6c95b51bb513e8f30460be17ccb5d4ed23353e8f35fa67de3401f978a5c8`。包内保持Version3.7.0且不伪造build-info，没有来源元数据时实际使用本地资源；四份新增许可齐全，旧后台依赖、笔记和大型服装未进入包。这是本地验证快照，不是新的正式Release。

## Consequences

发布时不再手工维护第二份默认版本号，远程核心JS/CSS随包的源码来源固定；本地未发布改动不会默认拼接既有正式标签。代价是没有有效构建元数据的源码安装在远程选项下仍使用本地文件；需要远程时应安装带来源信息的构建包，或显式指定核对过的固定引用。

完整提交号只标识构建来源，不保证CDN网络可用，也不能检测安装后手工改过的文件或伪造的构建元数据。显式覆盖仍允许选定不同版本，其兼容性由配置者负责。显式远程网络失败的行为保持现状。真实Safari、同一修改的Linux/MySQL CI及发布后的远程文件/安装包核对仍未覆盖；当前修改没有提交、推送、发布或部署。

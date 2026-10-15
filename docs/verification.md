# 验证记录

日期：2026-10-15。平台：macOS arm64。

## 初次 CI 基线（历史构建）

- WordPress 稳定标签：7.1.3。
- 官方基线 SHA：fc9832bef919c2a08541248db23af90d14ffb082。
- SQLite Database Integration：v3.1.0，SHA 6546e4538b708a1d8a565a1d31688bd63b610fa3。
- 集成提交：664346a5ae700e08c3f0e9ec4dfe005d206d9b11。
- 测试运行时：隔离编译的 PHP 8.4.26，PDO SQLite，SQLite 3.53.4。
- 测试 PHP 无 mysqli/pdo_mysql，不使用主机上的 MySQL 服务，邮件和外部 HTTP 被测试夹具拦截。

## 执行结果

`python3 -m unittest discover -s tests -v`：22 项通过，覆盖正式版本筛选、
数字排序、标签 SHA 变更、依赖锁、归档路径/符号链接、跨系列确认、真实 Git
rebase、冲突保留/恢复、锁变更拒绝、main/本地文件保留、核心篡改拒绝、显式
overlay 更新及 PR 发布目标/自动候选识别，以及驱动独立升级、过时文件移除、
候选去重与核心/驱动版本共同判定。

`php tests/environment.php` 和隔离测试 PHP 的同一检查：均通过。

`python3 tests/smoke.py --php .cache/test-runtime/install/bin/php`：通过。
测试从实际发行 ZIP 安装站点，验证真实 PDO SQLite 数据库和 WordPress HTTP
请求，没有用 mock 数据库替代安装验证。覆盖内容 CRUD、中文/emoji、序列化
option、自定义表 dbDelta、自增、事务回滚、integrity_check、WAL/FULL/锁等待、
核心更新保护、首页、REST、后台登录和访客隔离。重复安装拒绝；数据库/drop-in
缺失均返回 503，不重建或改变数据库。

数据库备份/恢复同样使用实际 PDO SQLite：验证 WAL 已提交内容的一致性快照、
HMAC/摘要/站点身份、篡改及越权目录拒绝、活动事务与互斥锁等 12 项保护。
恢复验证覆盖请求 shutdown 排空、等待超时、原数据库保留、健康检查失败后
维持 503、独立回退/完成、核心版本不匹配拒绝、维护进程被杀后保持停写，以及
不加载 WordPress 即可从数据库遗失状态恢复。此处仅为数据库备份，不是全站备份。

两次从该集成 SHA 构建的 ZIP 字节一致。当时的开发包：

`dist/wplite-0.1.0-dev-wp-7.1.3.zip`

SHA-256：`158eb5583e9b74bc4654acbf32ad907f904606cdc8e535ea6d4fa5df3b5a8da0`

打包文件数：5064。与固定官方基线中所有文件逐字节比对，未修改或删除核心文件。
两个 GitHub Actions YAML 已完成本地语法解析。

## 初次 GitHub 实际运行

项目远程仓库为 [zhpelo/WPLite](https://github.com/zhpelo/WPLite)，已推送工具
main 和锁定集成分支 `sync/wp-7.1.3-1u6m8mop`。被验证的工具提交为
`e159ed6f157324ec21c5b06c7ac6ffdea1431e24`。

- [Validate WPLite #38718827624](https://github.com/zhpelo/WPLite/actions/runs/38718827624)：成功。
  Ubuntu runner 上的 22 项工具测试、正式依赖检查、隔离 PHP 8.4 编译、核心
  一致性验证、真实 SQLite 安装/HTTP/备份恢复测试及开发 ZIP 上传全部通过。
- [Sync official stable tags #38718951634](https://github.com/zhpelo/WPLite/actions/runs/38718951634)：成功。
  手动运行确认核心和驱动均为 `up-to-date`，没有创建无更新的 PR。
- 两条工作流均为 active；同步计划为 UTC `17 */6 * * *`。已按维护者授权允许
  Actions 创建 PR，仓库默认 token 权限仍为 read。同步仅创建草稿，不自动批准、
  合并或发布。

Ubuntu CI 日志中的发行 ZIP SHA-256 与上面的 macOS 本地构建完全一致。CI
产物名称为 `wplite-development-build`，由上传工作流保留 14 天；GitHub artifact
外层归档的摘要不是内层发行 ZIP 的摘要，不应混用。

目前没有新上游版本，尚未实际运行新版本候选的远程 rebase、PR 创建、去重和
取代路径；这些路径有本地真实 Git 测试及发布策略测试，不能当作远程运行证据。
定时计划已启用，但首次 cron 触发记录尚未观察到。

## Web 安装器验证（后续开发构建）

在相同核心/驱动/PHP/SQLite 基线上，工具测试扩展到 26 项并全部通过，新增所有者
密钥生成的权限/保密/不覆盖检查，以及站点凭据不得进入 overlay 或构建产物的保护。

真实 HTTP 用例完成私有目录和父级 DOCUMENT_ROOT 下的子目录安装（含自定义表
前缀），管理员登录、访客隔离、重复安装拒绝、直接配置访问 404、无效/过期密钥、
跨 Origin、错误站点 URL、弱密码、路径遍历及并发安装拒绝。非 loopback HTTP
连凭据表单也不会显示；TLS 实际证书和可信代理配置仍未纳入本轮测试。

真实 PHP HTTP 服务器忽略 .htaccess 时，公开目录安装被拒绝。另验证错误公开
控制响应、302、500、仅拒绝 SQLite 扩展、遗漏实际 backups 前缀和 403 泄漏内容，
全部在创建数据库/站点配置前失败，并清理所有探针。探针使用真实敏感文件名及
backups/rollback 前缀，不仅是相似后缀的随机文件。

本地 Apache 2.4.66 作为非特权前端代理、隔离 PHP 8.4.26 作为后端，验证同一准备
目录保护后可安全重试，SQLite 安装、数据库/元数据/备份 HTTP 403、CLI 一致性
备份及独立恢复、拒绝规则篡改后的 HTTP 503；不修改或启动系统 Apache 服务。
已有完整 SQLite 内容/事务/HTTP/备份/恢复 smoke 套件也再次通过。

两条工作流已加入 Web/Apache 测试并固定 Ubuntu 24.04。
[Validate WPLite #38722940473](https://github.com/zhpelo/WPLite/actions/runs/38722940473)
在工具提交 `564d72d7a1aad044634b1fac6a1c5789f5b50bd4` 上全部通过，包括 26 项
工具测试、原 SQLite smoke、真实 Web/Apache 安装及开发 ZIP 上传。首轮 CI 发现
新增测试缺少局部 Git 作者身份；已修复，并在本地屏蔽全局 Git 配置后再次通过。
当前集成 SHA 以依赖锁为准，最新 ZIP 校验值以 dist 内生成的 .sha256 文件为准。

本轮本地集成提交为 `24f6029ad44d18375d9ffe21acca8deaaff032aa`，发行文件数
5070，ZIP SHA-256 为
`66b800cbc969324a3b033ae69c542e83cc6d50e6672d77dab78be5e27ae9be8e`。
重复构建及实际安装验证读取的是该集成 SHA，而不是未提交的 runtime 目录。

## 同步候选新鲜度回归

工具测试扩展到 44 项：新增本地裸 origin、真实 Git push/rebase/父提交/祖先检查，
配合内存中的 GitHub PR API 模拟，覆盖重复候选、同版本系统文件更新、验证代码
变化、仅文档变化、伪造 PR 正文指纹、较新驱动保护、人工 PR/人工追加提交保留，
以及 main 在推送前/推送后推进、PR 在去重/关闭前变更的停止与保留行为。
这些不是实际 GitHub PR 创建/关闭记录，未把模拟 API 当作远程执行证据。

额外验证 export-ignore/export-subst 不改变读取到的 Git 原始源文件、符号链接
拒绝、尚无首次 main 提交的本地引导，以及显式 canonical overlay 检查拒绝陈旧
集成而普通本地构建仍只读取锁定 SHA。发行内容未变化，5070 个文件及 ZIP
SHA-256 仍为上一节的 `66b800cbc969324a3b033ae69c542e83cc6d50e6672d77dab78be5e27ae9be8e`。
完整 SQLite smoke 与真实 Web/Apache 套件也再次通过。本轮工具提交为
`31eba155af6585b329d51c0b4a6fbb924a1e5a12`，真实运行记录：

- [Validate WPLite #38726705890](https://github.com/zhpelo/WPLite/actions/runs/38726705890)：成功，44 项工具测试、canonical overlay 门槛、完整安装与 Web/Apache 套件及构建上传均通过。
- [Sync official stable tags #38726705842](https://github.com/zhpelo/WPLite/actions/runs/38726705842)：由 main 输入变化的 push 实际触发并成功，确认当前无更新且 canonical overlay 匹配，未创建空 PR。

当前测试及发布仍在同一 runner job，末步注入 token 不等于隔离；在执行第三方
插件代码前必须拆分验证/发布权限。尚未把当前工作流认定为生产级供应链隔离。

## 诊断与 Cron 执行验证

新增 `tests/diagnostics.py` 从实际 ZIP 安装隔离站点，在 PHP 8.4.26＋SQLite
3.53.4、无 MySQL 扩展/服务的环境验证独立与已加载站点报告、可选 quick_check、
WAL 逻辑页数、报告白名单以及没有默认周期探针。私有路径、站点身份、盐及备份
认证密钥均不进入报告；缺少数据库时不重建，维护期间不打开数据库，错误身份、
损坏状态和不可读受管文件返回结构化失败，权限测试仅针对非 root 运行身份。

真实 HTTP 验证管理员/订阅者/访客权限、POST/nonce、未知检查方式、重复探针
复用和 option 不自动加载。真实原生 wp-cron.php 分别通过 CLI 与本站 HTTP 执行
事件；普通 do_action、HTTP 200 假响应和被阻止的流量 Cron 不会生成执行证据。
父/子进程回写保留实际完成记录。老观察、延迟执行、发行变化和损坏记录分别处理，
不把单次执行或配置开关提升为周期可靠性/商店认证。

测试邮件全部拦截，HTTP 只允许准确的本机 cron URL；没有访问外部邮件/支付等
业务服务。原 44 项工具测试、SQLite smoke 与 Web/Apache 套件保持通过，专用
诊断步骤已接入两条工作流。全主机/TLS/持续调度矩阵仍未完成。

当前本地集成为 `c15f409911dacf4fa11973f7a97868ade006ab17`，5073 个文件，ZIP
SHA-256 为 `570b6e8b29dd149edbdfb51cee62b7fe0e402982a0a6238e7aff333d33875b4f`。
工具提交 `be4733d021bd3bf61a0d310b4252301e588276bd` 的远端运行已核对：

- [Validate WPLite #38732188333](https://github.com/zhpelo/WPLite/actions/runs/38732188333)：44 项工具测试、完整 SQLite smoke、真实 Apache 存储保护及新增诊断/Cron 步骤全部成功；Ubuntu 产物 SHA-256 与上述本地构建相同。
- [Sync official stable tags #38732188363](https://github.com/zhpelo/WPLite/actions/runs/38732188363)：成功，确认当前核心/驱动无更新、overlay 匹配；发布步骤跳过，没有创建空 PR。

## 同步元数据请求限流修正

历史同步 [#38727400603](https://github.com/zhpelo/WPLite/actions/runs/38727400603)
在官方依赖发现阶段遇到 GitHub 匿名 API 限流而失败。两条工作流现在仅在可信
元数据步骤设置 `WPLITE_GITHUB_API_TOKEN`；请求只向固定社区驱动仓库的稳定
Release 元数据接口附加认证。归档、WordPress.org 校验接口和其他地址均不附加，
所有重定向（包括同主机）移除认证，HTTP 降级在请求前拒绝。没有扩大工作流权限，
也不把该变量传给候选 PHP/插件测试步骤；这仍不替代后续的独立发布作业隔离。

本地 47 项工具测试全部通过，新增认证接口白名单、匿名模式、重定向移除认证及
HTTPS/大小限制回归。认证不保证永不限流，仍保留 API 错误时停止、不绕过固定
SHA/摘要检查的行为。工具提交 `8ca31edf5f5fcc8671fc62200b7d642ad4d7b9e3` 的
[Validate WPLite #38733478583](https://github.com/zhpelo/WPLite/actions/runs/38733478583)
全部成功；[同步 #38733478217](https://github.com/zhpelo/WPLite/actions/runs/38733478217)
认证元数据检查及无更新路径成功，未创建空 PR。

## 同步验证与写权限发布隔离

工作流改为 discover、validate、publish 三个独立 Ubuntu runner。前两者只读；
最后一个持写权限但不运行 PHP、插件、主题或候选测试产物。仅以 discovery 的
不可变 artifact ID 和独立作业输出的 manifest SHA-256 交接，发布器再次检查
仓库、源提交、运行 ID/attempt、依赖转换、Git bundle 单一引用、核心原字节、
canonical overlay 与固定驱动归档。测试环境的 ZIP/Git/代码/缓存不进入发布环境。

工具测试共 62 项通过，其中交接与工作流结构回归 14 项；新版 `range-diff` 补充
用例也单独通过。发行内容未变化，仍使用此前锁定的 5073 文件集成和 ZIP 摘要。

本地真实 Git 用例覆盖三个独立仓库间候选传递、无更新传递、Git range-diff、
重新导入后原发布器的草稿 PR 路径（GitHub API 仍为模拟），并拒绝交接篡改、
跨运行重放、额外文件/引用、符号链接、来源指纹变化、未批准新系列、同标签替换、
受污染源码、导入后锁/结果变化及伪造自洽驱动清单。尚未把这些本地用例当作
远端新版本候选或第三方插件执行的证据。

工具提交 `7123261c92319eb4afff337e868387d0d6805797` 的真实
[三作业同步 #38735341480](https://github.com/zhpelo/WPLite/actions/runs/38735341480)
已成功。日志确认 discover/validate/publish 分别使用 runner ID
`1000000992`、`1000000993`、`1000000994`；前两者 `Contents: read`，只有发布者
拥有 `Contents: write` 和 `PullRequests: write`。发现产物 ID `11972026954`，
名称 `wplite-discovery-38735341480-1`，两个后续作业均按固定 ID 下载并返回
`handoff-verified`、`candidate: false`，发布器明确输出无远端更改。已核对本仓库
没有打开的 PR。此运行证明实际无更新交接及权限隔离，不证明真实候选 PR 生命周期。

## 全站快照创建与独立校验

新增 `site-backup-create`、`site-backup-verify`、`site-backup-complete`。本地
PHP 8.4.26＋SQLite 3.53.4 的真实 ZIP 测试在排空 shutdown 请求后，快照数据库、
中文/emoji 媒体、站点配置、插件/主题文件、隐藏文件、数字文件名、空文件与空目录。
40 MiB 单文件在 PHP `memory_limit=32M` 下创建成功；这是当前约 5,086 文件
测试站点的流式复制证据，不是任意文件数量或 WordPress 前台内存保证。

真实文件/清单篡改、错误密钥/站点身份、未签名额外文件、宽松快照权限、符号链接、
硬链接、FIFO、不可读源文件（非 root）、公开输出、覆盖既有目录和禁用 fsync
均被拒绝。外部文件写入在清单提交前使快照失败；校验不加载 WordPress，缺少
线上数据库及配置也不依赖或重建它们。备份创建遇到缺失数据库同样不创建空库。

复制后真实 fsync 的测试夹具可暂停进程；进程被杀后，HTTP 保持 503、没有完整
清单、原始文件保持不变。双向维护类型检查防止数据库恢复与全站备份误完成；
独立检查原数据库/运行文件后才重新开放。夹具不进入发行包、不伪造 fsync 成功。
真实非特权 Apache 还验证公开根内受保护数据树被精确排除，快照 JSON、数据库和
配置文件均返回 403。原 SQLite 安装/数据库备份恢复、Web 安装和诊断/Cron
套件也再次通过，62 项工具测试保持通过。

本轮本地锁定集成为 `1143d94171ec0bb850f9797bf11d0af734168435`，发行 5074
文件，重复构建及测试的 ZIP SHA-256 为
`3a2220ab907e432ad3f30b61317c4b559389e31a7d8e76f7addd7abebfb16700`。
新增专用步骤已加入普通 CI 和只读候选验证作业；其远端执行结果需按本轮提交
单独核对。全站替换恢复、独立 Web 恢复、外部写入者协调及断电/磁盘满全矩阵
仍未完成，不把快照通过当作完整恢复演练。

## 证据边界

这是开发构建及部分 M2 能力验证，不是生产认证。尚无实际同步 PR 记录，
也未完成 PHP 8.3/8.5、完整 Apache/Nginx/实际 TLS/受限虚拟主机矩阵、WooCommerce/插件组合、
资源基准、签名升级、全站替换恢复/Web 恢复、磁盘满/权限全矩阵及升级中断演练。
相同 ZIP 校验值已在本地重复构建及本次 Ubuntu CI 中得到验证；不代表任意
Python/zlib 工具链之间均保证字节一致。

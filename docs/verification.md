# 验证记录

日期：2026-10-15。平台：macOS arm64。

## 固定输入

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

两次从相同集成 SHA 构建的 ZIP 字节一致。本地最终开发包：

`dist/wplite-0.1.0-dev-wp-7.1.3.zip`

SHA-256：`158eb5583e9b74bc4654acbf32ad907f904606cdc8e535ea6d4fa5df3b5a8da0`

打包文件数：5064。与固定官方基线中所有文件逐字节比对，未修改或删除核心文件。
两个 GitHub Actions YAML 已完成本地语法解析。

## GitHub 实际运行

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

## 证据边界

这是 M1 开发构建验证，不是生产认证。尚无实际同步 PR 记录，
也未完成 PHP 8.3/8.5、Apache/Nginx、受限虚拟主机、WooCommerce/插件组合、
资源基准、签名升级、全站备份/Web 恢复、磁盘满/权限错误及升级中断演练。
相同 ZIP 校验值已在本地重复构建及本次 Ubuntu CI 中得到验证；不代表任意
Python/zlib 工具链之间均保证字节一致。

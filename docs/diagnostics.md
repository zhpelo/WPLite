# 运行诊断与 Cron 执行证据

这是开发版运维功能，不是商店生产认证。后台入口为「工具 → WPLite 诊断」，仅
具有 manage_options 权限的管理员可访问；安排探针仅接受带有效 nonce 的 POST。
普通访客、订阅者、缺失/错误 nonce、GET 操作和未知检查方式均不能安排任务。

## CLI 与检查范围

```sh
php wplite/cli.php doctor
php wplite/cli.php doctor --data-dir /private/site
php wplite/cli.php doctor --data-dir /private/site --integrity quick
php wplite/cli.php doctor-site
php wplite/cli.php doctor-site --integrity quick
```

- doctor：保留原有独立 PHP/SQLite 环境检查，不加载 WordPress。
- doctor --data-dir：验证现有站点状态、受管文件摘要及核心版本、存储保护配置、
  可写性、可用于数据库备份的磁盘余量、维护状态、现有数据库身份和 WAL 模式。
  不加载 WordPress、不创建数据库；维护期间不打开数据库。
- doctor-site：正常加载 WordPress，再检查实际连接的 FULL、5000ms 锁等待、
  1000 页 checkpoint 和请求锁，附加 Cron 执行状态。
- --integrity quick：显式执行 quick_check(1)。默认不扫描全库，避免诊断页面
  给大站点带来额外扫描负担。quick_check 也不代替备份认证或全站恢复演练。

数据库备份余量估计使用 SQLite page_count×page_size，包括 WAL 视图中的逻辑
页面，不仅是主文件大小；仍不代表预留空间或全站媒体备份容量承诺。

独立检查使用已有数据库的 mode=ro URI，包含实时 WAL 数据，不使用可能漏掉 WAL
的 immutable 快照模式。SQLite 可以管理只读连接的协调 sidecar；不会创建新的
主数据库或写业务数据。加载站点的检查会运行正常 WordPress/插件加载钩子，不能
当成完全无副作用的离线检查。损坏站点请优先使用独立 doctor 和已有恢复工具。

报告采用字段白名单，不包含绝对数据路径、站点身份、WordPress 盐、安装密钥、
备份认证密钥、原始私有状态或 PDO 错误信息。仍包含版本、模式与磁盘余量等运维
信息，分享前应检查用途。ok/退出码 0 仅表示该 scope 的基础检查通过，不表示
全生态兼容、持续 Cron、数据完整性（未显式请求时）或生产商店认证。

## Cron：配置、传输、执行分开

```sh
php wplite/cli.php cron-probe --transport schedule
php wplite/cli.php cron-status
php wplite/cli.php cron-status --require-execution yes
php wplite/cli.php cron-probe --transport loopback
```

schedule 只安排本系统的一次性 canary，不主动发送额外的检查 HTTP 请求；WordPress
本身仍可能按其配置在加载/流量请求结束时正常触发 Cron。可等待已有外部调度器，
或由管理员运行 php wp-cron.php；该原生入口会执行其他到期任务。

loopback 安排探针后通过固定 WP_SITEURL 请求本站 wp-cron.php，不接受调用者传入
的任意 URL，不发送 cookies 或认证秘密，验证 TLS，不跟随重定向，最多等待约 5 秒。
**这会触发所有已到期 WordPress 任务，可能包含发送邮件、订单和更新任务。**
单 worker、TLS/网络限制等情况可能导致请求失败；不绕过验证或将失败当成成功。

状态中的 automatic_trigger 仅说明流量触发配置。DISABLE_WP_CRON=true 不代表
外部 Cron 无法执行，ALTERNATE_WP_CRON 也不代表可靠。http.code=200 只说明请求
收到响应；只有真实 DOING_CRON 上下文执行了匹配事件，才记录 execution_observed。
普通 do_action 调用不会生成观察证据；异步 PHP 返回 HTTP 后也可能稍后才完成事件，
应刷新页面或轮询状态，而不是只看第一个 HTTP 响应。

探针使用不自动加载的单个 option，重复点击复用仍在等待的事件，旧探针会清理本
系统自己的 hook，不处理业务任务。文件互斥锁保护父/子进程回写，等待 loopback
时释放锁，避免请求互相等待或父请求覆盖已完成记录。没有常驻进程或默认周期探针。
同一待执行探针的 loopback 尝试有 10 秒节流；重复点击不一定发出新的检查请求。

状态可为 not_requested、pending、expired、executed、executed_late、failed、
stale_release、invalid_record。等待窗口为 120 秒，晚执行仍记录但明确标为 late。
发行清单摘要变化后旧观察失效；超过一天的观察不作为当前 scheduled_features 的
基础。recurring_scheduler_verified 始终为 false：当前单次机制不认证外部调度器
配置、持续运行周期、队列吞吐或 Action Scheduler 的业务正确性。

cron-status 默认退出码只表示成功读取状态；--require-execution yes 在缺乏当前
发行的执行证据时返回 1。它不是持续可靠性认证，也不自动启动新探针。

## 能力等级与边界

content=configuration_ready 是离线配置/存储检查；basic_runtime_ready 是已加载
站点的基础策略检查。scheduled_features=observed_once 表示近期观察到一次真实
执行，不表示及时、周期稳定或所有业务任务成功。commerce 保持 unverified，
production_certified=false；兼容目录、商店业务流程、签名更新、完整恢复和环境
矩阵仍在路线图中，不能靠一个 Cron 成功事件提升认证等级。

公开目录存储仅检查当前拒绝规则文件匹配，并展示安装时 HTTP 检查时间；本诊断
不会重新证明外部 Apache/Nginx/CDN 配置没有变化。更换规则后仍须独立核验数据库
和备份 URL 不可下载，不能把配置摘要匹配当作实时网络隐私证明。

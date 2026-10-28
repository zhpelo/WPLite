# WPLite

为 WordPress 提供轻量的 SQLite 安装方式：**不修改核心，支持全新安装和已有 MySQL 单站点的可选迁移。** 使用 SQLite 后不需要 MySQL 服务，但迁移不会删除原数据库。

WPLite 采用精简 MU 插件和 SQLite 数据库 drop-in，已内置
[WordPress SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration)
驱动。目标是减少独立维护 MySQL 服务的资源和运维负担；实际性能取决于主机、插件和访问负载，不保证所有场景都更快。

## 安装要求

- 官方 WordPress 单站点：可全新安装，或由管理员确认迁移已有 MySQL 数据。
- Linux/POSIX 主机，PHP 8.3–8.5；使用 SQLite 或迁移到 SQLite 时需启用 PDO SQLite。
- SQLite 3.37.0 或更高版本。按 PDO 实际可用驱动检测，不依赖扩展名称；兼容当前静态 PHP 提供的旧式 PDO SQLite 接口。选择 MySQL 时沿用 WordPress 的 MySQL 环境要求，无需 PDO SQLite。
- 可写的本地磁盘，以及通过主机面板或 FTP 上传文件的权限。HTTP 和 HTTPS 均可访问安装页，建议使用 HTTPS。

不需要 SSH、终端命令、Python、Composer，也不需要安装时下载驱动。

## 全新站点安装

1. 通过主机面板上传并解压官方 WordPress，先不要运行 WordPress 安装向导。
2. 将 WPLite 的 `wp-content/db.php`、`wp-content/mu-plugins/wplite.php`
   和 `wp-content/mu-plugins/wplite/` 上传到 WordPress 的对应位置。
   只适用于全新站点，不要覆盖其他数据库插件的 `db.php`；不是通过后台“上传插件”安装。
3. 浏览器访问网站首页，WordPress 会转到官方 `wp-admin/setup-config.php`。
   WPLite MU 插件在这个配置阶段提供 SQLite / MySQL 选择，默认选中 SQLite。
4. 点击“继续安装 WordPress”。选择 SQLite 时无需填写数据库账号或存储路径，程序自动配置；
   选择 MySQL 时进入官方数据库连接表单，填写数据库名、账号、密码、主机和表前缀。
5. 自动进入官方 `wp-admin/install.php`，填写站点标题、管理员账号、邮箱和密码，完成安装并登录。

安装入口对所有访客开放，上传后请立即完成安装，以免被他人抢先设置。
HTTP 会明文传输管理员密码，生产环境建议使用 HTTPS。

选择 SQLite 时，WPLite 只创建数据库和 `wp-config.php`；选择 MySQL 时由官方流程生成配置。
管理员和站点初始化均沿用官方安装页面。
不修改 `setup-config.php`、`install.php` 或其他核心文件，不再提供独立安装入口。
已有配置时拒绝重复配置；安装锁可以保留。安装前后都不要删除或替换数据库 drop-in。

## 数据库与权限

旧 SQLite（如 3.45.2）使用 DELETE 日志模式，避免已知 WAL-reset 数据损坏问题；
已修复的 3.44.6、3.50.7 和 3.51.3+（不含 3.52.0）使用 WAL。
两种模式均保留 FULL 同步，不关闭事务日志；DELETE 模式的读写并发能力较弱。

新安装及 MySQL 迁移统一将数据库放在 `wp-content/wplite-data-随机字符/site.sqlite`，
不再尝试上级目录。创建数据库前，通过真实 HTTP 请求确认服务器禁止下载数据库及相关文件。

存储位置自动选择，不提供路径设置。Nginx、不支持 `.htaccess` 或限制本站 HTTP 请求的主机，
需要由主机配置可验证的目录访问禁止规则，并允许本站 HTTP 请求。
无法满足要求时会拒绝 SQLite 安装，可以选择 MySQL；不会降低保护要求。
随机目录名不能代替访问控制；以后修改服务器配置时，也必须保留数据库保护规则。

如果安装中途失败，请通过主机面板检查提示及本次新建文件，不要直接删除数据库或
反复覆盖重装。当前不提供自动清理、备份或恢复功能。

## 已有 MySQL 站点迁移

1. 上传相同的 WPLite drop-in 和 MU 文件；已有其他插件的 `db.php` 时不要覆盖。
   WPLite 在管理员开始迁移前继续使用原 MySQL，不会因为上传文件自动迁移。
2. 管理员登录后台后会看到迁移提示。部署后先等待旧请求结束，并停止其他程序直接
   写入本站表；确认接受短暂停站及校验后自动切换，再点击“开始迁移，完成后自动切换”。
   `actionscheduler_logs`、`woocommerce_log`、`woocommerce_sessions`（均使用实际站点表前缀）
   只迁移表结构，不复制内容；访客尚未结账的购物车会清空。`options` 表内的
   `_transient_`、`_site_transient_` 前缀缓存及其过期时间记录不复制，运行后会按需重建。
   其他插件表默认保留内容，包括订单、库存、订单备注、下载记录和待执行的定时任务。
3. WPLite 暂停新的 WordPress 请求（包括前台、后台、REST 和定时任务）。保持迁移页面打开，
   浏览器会连续提交短请求，分批复制和复核当前站点前缀的数据表，包括插件表。
   单次请求共享最多 1000 行、约 2 秒的处理预算，小表完成后会连续处理下一张表，
   不再每张表单独请求；大表仍按游标分批。校验逐表行数、逐行 SHA-256 哈希及重复行数量，
   并检查 SQLite 完整性。原 MySQL 只读访问，进度请求中的 WordPress 连接也设为只读。
   复制时记录源数据哈希，副本按此基线校验一次；不再重复全量读取原 MySQL，也没有第二轮副本复核。
4. 副本及完整性校验通过后自动切换，流程中不再弹出确认页面。期间可取消并恢复 MySQL 站点。
   发布前仍检查源表结构、表清单及原配置，再原子更新 `wp-config.php`，插入一条私有 SQLite
   配置加载语句。原有密钥、表前缀和其他配置保留，**原 MySQL 数据不修改、不删除**。

取消或切换前失败会解除暂停并保留原配置。浏览器关闭、网络中断或请求异常时不自动重试；
复制及校验的暂停租期为 2 分钟，成功的批次会续期。
如果 PHP 仍持有迁移独占锁，需等该请求结束才恢复访问。过期副本不能继续切换，需重新迁移。
未启用的 SQLite 副本可能保留在数据库目录，不会被自动使用，也不自动删除。
迁移成功后不要删除私有数据库、其中的配置文件或 MU 目录内的迁移状态文件。

迁移采用浏览器分批请求，不再要求单次请求完成整站复制，也不延长服务器执行时限。
写入使用多行 INSERT；有主键的 MySQL 表及可访问内部 rowid 的 SQLite 表按游标翻页，
避免随进度增加反复扫描已处理的行。无主键的 MySQL 表仍使用完整行哈希排序和 OFFSET，
以保证重复行及长文本的校验正确性。
每批保留最多 8 秒的程序处理预算，并按 PHP 可用执行时间预留清理时间。
单次建表、数据库查询和完整性检查仍是数据库操作，极慢查询或超大单行仍可能触及主机限制；
这种情况下会停止迁移，不会跳过校验。分批期间必须持续停止外部程序直接写入数据库，
MySQL 表读锁仅在各批请求中持有，不能跨 HTTP 请求保留。
不提供后台任务、失败后续传或不停机增量同步。主机需要可用的文件锁、MySQL `LOCK TABLES` 权限，
以及可写的标准 `wp-config.php` 和其所在目录、默认 `wp-content` 目录。
迁移副本统一存放在 `wp-content/wplite-data-随机字符/site.sqlite`，只有真实 HTTP
验证确认数据库、日志及锁文件均返回 403/404 后才会继续，否则拒绝迁移。
自定义数据库常量/加载方式、多站点、视图、触发器、真正的虚拟/存储生成列、外键表和无法无损复制的
数据会被拒绝，不会静默跳过；MySQL 的 `DEFAULT_GENERATED` 表示表达式默认值，不作为生成列拒绝。
旧版 MySQL/MariaDB 的 `NO_AUTO_CREATE_USER` 只影响账户授权，不涉及站点表数据，迁移时会忽略该已移除模式；
日期、严格校验等其余会影响数据的 SQL 模式仍与源会话保持一致。
这里是直接迁移当前数据库，不支持上传任意 `.sql` 文件。

数据校验不等于所有插件运行兼容；生产站点请先在副本试迁移。当前没有自动回滚或反向同步，
切换后 SQLite 的新数据不会回写 MySQL。源码、数据库和外部持久对象缓存不应在迁移中途更改；
启用外部持久对象缓存时会拒绝迁移，需先停用并清理，避免跨数据库使用旧缓存内容。

## 更新与兼容性

- WordPress 核心、普通插件和主题沿用各自原有更新机制，WPLite 不接管更新。
- WPLite MU 插件和内置 SQLite 驱动需要手动部署更新，不提供自动更新器。
- 遵循 WordPress 插件接口并不等于所有插件和主题都兼容 SQLite。依赖 MySQL
  专属语法、直接连接 MySQL 或写入密集的插件，需要单独确认兼容性与负载表现。
- 当前面向单站点、本地磁盘，不支持多站点或网络共享数据库文件。

## 项目范围

当前优先快速上线，只维护必要的安装、管理员确认的 MySQL 迁移与 SQLite 运行逻辑。不维护 WordPress fork、
核心同步/rebase、整站发行、tools、checks、测试平台、备份恢复或 ePHPm。

`wp-content/` 中的 WPLite drop-in 和 MU 文件为交付内容，本地运行用的官方核心不随项目发行；
`AGENTS.md` 为开发约束。内置驱动保留上游源码和许可证，不自行开发 SQL 翻译器。


rm -f WPLite-0.2.0.zip && (cd webroot && zip -X -r ../WPLite-0.2.0.zip wp-content/db.php wp-content/mu-plugins/wplite.php wp-content/mu-plugins/wplite -x 'wp-content/mu-plugins/wplite/migration-state*.php' '*/.DS_Store')

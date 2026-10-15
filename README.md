# WPLite

为全新 WordPress 站点提供轻量的 SQLite 安装方式：**不修改核心，不需要 MySQL，上传 PHP 文件即可安装。**

WPLite 采用精简 MU 插件和 SQLite 数据库 drop-in，已内置
[WordPress SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration)
驱动。目标是减少独立维护 MySQL 服务的资源和运维负担；实际性能取决于主机、插件和访问负载，不保证所有场景都更快。

## 安装要求

- 全新、尚未配置的官方 WordPress；当前不支持已有 MySQL 站点迁移。
- Linux/POSIX 主机，PHP 8.3–8.5，并启用 PDO SQLite。
- SQLite 3.37.0 或更高版本。按 PDO 实际可用驱动检测，不依赖扩展名称；兼容当前静态 PHP 提供的旧式 PDO SQLite 接口。
- 可写的本地磁盘，以及通过主机面板或 FTP 上传文件的权限。HTTP 和 HTTPS 均可访问安装页，建议使用 HTTPS。

不需要 SSH、终端命令、Python、Composer，也不需要安装时下载驱动。

## 安装步骤

1. 通过主机面板上传并解压官方 WordPress，先不要运行 WordPress 安装向导。
2. 将 WPLite 的 `webroot/wp-content/db.php`、`webroot/wp-content/mu-plugins/wplite.php`
   和 `webroot/wp-content/mu-plugins/wplite/` 上传到 WordPress 的对应位置。
   只适用于全新站点，不要覆盖其他数据库插件的 `db.php`；不是通过后台“上传插件”安装。
3. 浏览器访问网站首页，WordPress 会转到官方 `wp-admin/setup-config.php`。
   WPLite MU 插件在这个配置阶段显示 SQLite 配置，无需填写 MySQL 账号或安装口令。
4. 通常保持默认存储选项，点击“配置 SQLite，继续安装 WordPress”。
5. 自动进入官方 `wp-admin/install.php`，填写站点标题、管理员账号、邮箱和密码，完成安装并登录。

安装入口对所有访客开放，上传后请立即完成安装，以免被他人抢先设置。
HTTP 会明文传输管理员密码，生产环境建议使用 HTTPS。

WPLite 只创建 SQLite 数据库和 `wp-config.php`，管理员和站点初始化沿用官方安装页面。
不修改 `setup-config.php`、`install.php` 或其他核心文件，不再提供独立安装入口。
已有配置时拒绝重复配置；安装锁可以保留。安装前后都不要删除或替换数据库 drop-in。

## 数据库与权限

旧 SQLite（如 3.45.2）使用 DELETE 日志模式，避免已知 WAL-reset 数据损坏问题；
已修复的 3.44.6、3.50.7 和 3.51.3+（不含 3.52.0）使用 WAL。
两种模式均保留 FULL 同步，不关闭事务日志；DELETE 模式的读写并发能力较弱。

默认优先将数据库放在网站公开目录之外。如果主机不允许，安装程序只在 HTTP 验证
确认服务器禁止下载数据库及相关文件后，才启用站内受保护目录。

Nginx、不支持 `.htaccess` 或限制本站 HTTP 请求的主机，可能需要在安装页指定
网站公开目录之外的可写私有路径。无法满足要求时会拒绝安装，而不是降低保护要求。
随机目录名不能代替访问控制；以后修改服务器配置时，也必须保留数据库保护规则。

如果安装中途失败，请通过主机面板检查提示及本次新建文件，不要直接删除数据库或
反复覆盖重装。当前不提供自动清理、迁移、备份或恢复功能。

## 更新与兼容性

- WordPress 核心、普通插件和主题沿用各自原有更新机制，WPLite 不接管更新。
- WPLite MU 插件和内置 SQLite 驱动需要手动部署更新，不提供自动更新器。
- 遵循 WordPress 插件接口并不等于所有插件和主题都兼容 SQLite。依赖 MySQL
  专属语法、直接连接 MySQL 或写入密集的插件，需要单独确认兼容性与负载表现。
- 当前面向单站点、本地磁盘和全新安装，不支持多站点或网络共享数据库文件。

## 项目范围

当前优先快速上线，只维护必要的安装与 SQLite 运行逻辑。不维护 WordPress fork、
核心同步/rebase、整站发行、tools、checks、测试平台、备份恢复或 ePHPm。

`webroot/` 中的 WPLite drop-in 和 MU 文件为交付内容，本地运行用的官方核心不随项目发行；
`AGENTS.md` 为开发约束。内置驱动保留上游源码和许可证，不自行开发 SQL 翻译器。

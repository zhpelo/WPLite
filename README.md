# WPLite

不修改 WordPress 核心的 SQLite 安装包：精简 MU 插件 + 数据库 drop-in。
SQLite 驱动已包含在 `plugin/` 中，用户无需 Python、Composer、SSH 或终端命令。

## 安装（仅全新站点）

1. 在主机面板上传、解压官方 WordPress，暂时不要执行其安装向导。
2. 将本仓库 `plugin/` **里面的内容**上传到 WordPress 根目录，与 `wp-admin/`
   同级。也可以将 WPLite 上传包直接解压到该目录；不是在后台“上传插件”。
3. 浏览器打开 `https://你的域名/wplite-setup.php`。按页面提示，通过主机面板
   新建所有者凭据文件，填写站点和管理员信息，提交安装。
4. 安装完成后登录 WordPress，并通过面板删除 `wplite-setup.php` 和剩余的
   `wplite-setup-key.php`。

安装页创建 SQLite 数据库、`wp-content/db.php`、标准 `wp-config.php` 和管理员，
不改动官方核心，不覆盖已有站点。所有者凭据用于防止陌生访客抢先安装。
安装中途失败时停止写入，需通过主机面板检查本次新建文件，不提供自动恢复。

## 主机要求

- Linux/POSIX、PHP 8.3–8.5、PDO SQLite、可写的本地磁盘、HTTPS。
- SQLite 3.51.3+（排除 3.52.0），或已修复的 3.44.6 / 3.50.7。
- 默认把数据库放在网站公开目录外；无法使用私有目录时，只在本站 HTTP 验证
  确认服务器禁止下载数据库后才使用站内目录。Nginx 或限制本站 HTTP 请求的
  主机可能需要由主机面板指定私有路径，不保证所有共享主机都能直接运行。
- 站内存储依赖服务器持续禁止访问数据库及 WAL/SHM 文件。以后更改服务器配置
  必须保留保护规则，PHP 本身不能拦截静态数据库文件下载。

## 范围

WordPress 核心、普通插件和主题沿用官方更新机制。WPLite MU 插件及驱动需要手动
部署更新，不承诺任意插件、主题或未来核心版本都兼容。

当前不开发 tools、checks、测试平台、核心同步、整站发行、备份恢复、MySQL 迁移、
多站点或 ePHPm。实际驱动来自
[WordPress SQLite Database Integration](https://github.com/WordPress/sqlite-database-integration)，
固定版本和归档 SHA-256 记录于 `dependencies.lock.json`；上游源码及许可证原样保留。

安装页已在本地 PHP 8.4.26 / SQLite 3.53.4 验证浏览器安装和登录，包括 Apache
站内保护场景；也验证过官方 WordPress 7.1.2 → 7.1.3 升级及数据保留。
这些结果不代表所有共享主机或第三方插件均兼容，不预估资源节省百分比。

# WPLite

精简的 WordPress SQLite 集成包：**官方 WordPress 不改核心，数据库使用 SQLite。**

WPLite 不是 WordPress fork，不维护核心集成分支，不 rebase 官方源码，不发布整站
安装包。WordPress 核心、插件和主题继续使用其原有更新机制。

## 组成

- `wp-content/db.php`：WordPress 数据库 drop-in，加载社区 SQLite 适配器。
- `wp-content/mu-plugins/wplite.php`：小型 MU 插件，只添加站点健康信息。
- `wp-content/mu-plugins/wplite/`：薄启动检查和固定版本的上游驱动。
- `tools/install.php`：一次性本地 CLI 配置工具，仅支持全新站点。

不自研 SQL 翻译器，不包含备份恢复、维护锁、Web 安装器、整包升级器、常驻服务
或 ePHPm。SQLite SQL 兼容能力来自
[WordPress 社区 SQLite 集成](https://github.com/WordPress/sqlite-database-integration)。

## 全新站点安装

要求 PHP 8.3–8.5、PDO SQLite、本地可写磁盘。SQLite 需要 3.51.3+（排除
3.52.0），或已修复的 3.44.6/3.50.7。数据库必须在真实 Web 根目录之外；不支持
网络共享磁盘、多站点或现有 MySQL 数据迁移。PHP 进程须能读取配置、写入私有数据。

1. 从 [WordPress 官网](https://wordpress.org/download/) 安装**尚未配置**的官方文件。
2. 在本仓库准备插件依赖（只下载校验过的 SQLite 驱动，不下载核心）：

   ```sh
   python3 tools/wplite.py prepare --output .cache/wplite-plugin
   ```

3. 用满足要求的 PHP 执行配置；替换以下三个路径。私有目录的父目录必须存在，目标
   `example-sqlite` 必须不存在。Web 根目录必须是服务器真实公开目录，不能随意填小。

   ```sh
   php tools/install.php --package .cache/wplite-plugin \
     --wordpress-root /srv/www/example \
     --public-root /srv/www \
     --data-dir /srv/private/example-sqlite
   ```

4. 打开站点的 `/wp-admin/install.php`，使用 **WordPress 自带安装页面**完成安装。

配置工具只新增 WPLite 文件、空 SQLite 数据库和标准 `wp-config.php`，不修改官方
核心文件。已有配置、drop-in、WPLite 目录或数据目录时一律拒绝覆盖。失败的首次
配置可能保留新建文件，须由所有者检查后处理，不会自动删除。详见 [安装说明](docs/install.md)。

## 更新与边界

- WordPress 自行更新；WPLite 不拦截核心、插件或主题更新。
- MU 插件没有普通插件的标准更新通知/自动更新流程。WPLite 及驱动需明确部署
  更新，当前没有自建自动更新器；不要只删除 MU 目录或 db.php 来切换数据库。
- “符合插件接口”不等于所有未来核心或第三方插件都兼容。CI 只读验证最新稳定版，
  不生成同步分支、自动 PR 或整站发行包。
- 备份由站点所有者另行安排，必须使用支持 SQLite 一致性快照的流程；不要在站点
  写入时只复制主数据库文件而忽略 WAL。当前不提供备份、恢复或迁移命令。
- 默认 WAL、FULL、5 秒锁等待；不重试整个业务请求。不承诺任意并发写入能力、
  WooCommerce 全兼容或固定资源节省百分比。
- 旧 WPLite 整站开发包与本版本不是同一安装方式，**不提供自动原地转换**。旧
  `dist/` 安装包不是当前交付物，不要与新插件混用。

## 开发验证

```sh
python3 -m unittest discover -s tests -v
python3 tools/prepare_test_runtime.py
.cache/test-runtime/install/bin/php -n tests/environment.php
python3 tests/smoke.py --php .cache/test-runtime/install/bin/php
```

兼容测试临时下载并按官网校验值核对 WordPress，从稳定基线安装，再使用官方
`Core_Upgrader` 升级到当前稳定版，执行 `wp_upgrade()` 并验证 SQLite 内容、SQL、
HTTP、登录和更新接口。外部 HTTP/邮件在测试站内阻断，核心更新包预先下载校验；
不测试公网下载传输或证明无人值守调度。测试核心不会被纳入插件包。

当前证据与未验证范围见 [验证记录](docs/verification.md)，设计见
[架构](docs/architecture.md) 与 [范围](docs/roadmap.md)。

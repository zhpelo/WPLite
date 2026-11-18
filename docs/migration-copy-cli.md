# 数据库迁移副本 CLI（开发接口）

新增 `wp-content/mu-plugins/wplite/copy-cli.php`，仅接受 CLI 标准输入 JSON。
它与浏览器 `Migration` 共用 `ConversionCore`，不加载站点 wp-config、插件或浏览器处理器，
不暂停线上站点、不改写配置、不执行发布切换。浏览器原有认证、租期、请求锁和自动发布行为保留。

支持两种输入，复用同一转换核心：

- 原隔离副本接口：`isolated_snapshot=true`，库名以 `wplite_migration_` 开头，调用方负责一致性快照。
- 原容器内转换：`maintained_source=true`，直接连接原站数据库。旧管理器先暂停本站 Web、cron、timers，
  排空请求并确认其他后台/外部写入已停止，容器继续运行。CLI 将当前站点所有表一次加 READ 锁，
  持有到复制、哈希和 SQLite 完整性检查结束；批次不会释放整个锁集合。也支持维护中的 MyISAM 表。

`maintained_source` 只是调用方声明，不代替服务维护证明；程序不能因此允许在线搬迁后直接切换。
此入口不改写原 MySQL 或原 wp-config。使用容器已有的 PHP 8.3–8.5、mysqli 和 PDO SQLite 3.37+，
不要求宿主机 PHP、MySQL 导出或隔离库。缺少运行时依赖应在停站前由管理器预检阻断。

标准输入字段：

- `database`、`prefix`、`snapshot_id`：源库、实际表前缀、不可变副本标识。
- `host`、`port`（可选）、`socket`（可选）、`user`、`password`：源库连接，禁止放在 argv。
- `wordpress_root`：固定版本转换组件内受信任的 WordPress core 目录，含 `wp-includes/class-wpdb.php`。
- `output`：绝对规范路径；父目录必须已存在且权限 0700，输出目录必须不存在。
- `timeout_seconds`：1–86400，默认 3600。

成功生成 `site.sqlite` 和 `report.json`，目录 0700，CLI 文件输出 0600。
报告包含核心版本及摘要、驱动版本、表结构摘要、复制行数、明确省略规则、SQLite 摘要和表前缀。
报告不包含源连接凭据。失败退出码 1，仅输出阶段码；失败目录不得作为已完成副本使用，
不得将遗留 SQLite 文件单独发布。已存在目录拒绝覆盖，不自动删除或续用失败副本。

恢复配置只包含驱动版本、数据库文件名和表前缀；完整目标 wp-config 仍须由目标恢复流程生成，
不能打包旧 MySQL wp-config 或原站数据库凭据。

## 本地验证

`tests/copy-fixture.sql` 只在新建临时 MySQL 库执行；使用相同输入 JSON 经标准输入运行
`php tests/copy-core.php`。测试比较浏览器核心与 CLI 的逐表结果，覆盖重复行、二进制、序列化、
大整数、小数、自定义表前缀及省略规则。该测试不启动系统服务、不发现线上凭据。
CLI 端到端试验使用本地临时 mysqld、独立数据目录和 Unix socket，不触碰项目当前站点数据库。

## Portable configuration generation

`configuration-cli.php` is CLI-only and accepts a JSON object on stdin with
`configuration` (original PHP source), `prefix`, and `storage` (a generated
`wplite-data-` directory with 16 lowercase hex characters). It emits a new
SQLite configuration via the existing Setup configuration generator. It does
not include or evaluate the old configuration. MySQL credentials are removed;
the eight authentication keys/salts and supported literal constants remain.

Dynamic expressions, additional includes, duplicate definitions, multisite,
custom content/plugin paths and existing WPLite configuration are rejected.
Unsupported configurations require review; this tool must not silently replace
business settings with defaults. Keep its stdin/stdout private: the generated
configuration still contains authentication salts. Regression test:
`php tests/copy-configuration.php`.

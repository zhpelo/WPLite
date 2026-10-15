# 精简架构

## 不变约束

WPLite 是 SQLite 集成包，不是 WordPress 发行版。官方核心零修改，不包含核心
源码、核心版本锁、rebase 工具、同步 PR、整站构建或核心更新拦截。

调用链：标准 `wp-config.php` → 薄 bootstrap → 官方 `wp-settings.php` →
`wp-content/db.php` → 社区 SQLite 驱动 → PDO SQLite；随后官方加载 MU 插件。
所有数据库查询仍经 `$wpdb` 与上游适配器，不重写 SQL 解析器。

## 运行部分

- bootstrap 在数据库初始化前检查 PHP/SQLite、已存在的私有数据库及必需文件。
  缺失数据库不能静默重建；缺失 db.php 不能回退到 MySQL。它不是完整的文件
  篡改防护、发行签名或全文件完整性系统。
- db.php 是 WordPress 正式支持的 drop-in，不是核心补丁。固定驱动的 WAL/FULL、
  busy_timeout=5000、wal_autocheckpoint=1000。持久性优先，不声称极限写入性能。
- MU 插件只注册懒执行的 Site Health 信息，不注册定时任务、后台恢复界面、
  维护请求锁或任何更新禁用钩子。诊断不暴露数据库路径或密钥。
- 仅支持 Linux/macOS 的 POSIX 路径、默认 wp-content/MU 目录布局、单站点、单主机、
  本地磁盘与全新安装。不支持的平台在配置写入前拒绝，避免错误判断公开路径。
  数据库在真实 Web 根目录之外，不再增加公开目录存储探针和受限主机适配。
- 配置工具拒绝现有站点；写入完成后，由官方安装页面完成站点初始化。

## 开发与依赖

`plugin/` 仅放自己的 drop-in/MU 源码。`dependencies.lock.json` 只固定社区 SQLite
正式发行的 URL 和 SHA-256，不固定 WordPress。prepare 校验驱动，复制源码及原始
驱动文件到 `.cache/plugin-*`，不混入数据库、配置、核心或测试插件。

核心由用户通过 WordPress 自身渠道更新。驱动和 WPLite 是独立部署依赖，MU 不会
自动拥有普通插件更新体验。驱动升级需改依赖锁、跑测试并人工部署，不自动取最新版。

## 只读兼容性 CI

push/PR/手动/每周验证，仅 contents:read。官方稳定核心是临时测试输入，不是
仓库依赖或发行物。真实 SQLite 安装、原生核心升级、升级后数据/接口回归、核心
字节核对和必要负面用例是当前范围。PHP 8.4 本地结果不能代表完整 PHP/主机矩阵。

备份恢复、Web 安装器、组合发布、签名整包升级、MySQL 迁移、多站点、电商认证、
常驻 PHP/ePHPm 均不在本次交付内。维护工具的历史实现在 Git 历史中保留。

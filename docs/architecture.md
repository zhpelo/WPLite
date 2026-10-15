# WPLite 架构约定

## 首版边界

传统 PHP 安装包；SQLite 是唯一支持的数据库；优先内容站，验证 WooCommerce
核心业务流程。真实支付网关逐个认证。尽量兼容虚拟主机，但不绕过存储保护、
文件锁、PHP 扩展和 SQLite 修复版本要求。单站点、单主机、全新安装。

运行链路：WordPress/插件/主题 → `$wpdb` → 社区 MySQL 兼容层 → PDO SQLite。
保留原有插件、主题、Hooks、REST API、表名和数据模型，不自行重写 SQL 解析器。

## 系统组件

- `wp-config.php` 在加载 WordPress 前引入 WPLite bootstrap，验证 SQLite 环境、
  必需 drop-in 和站点身份；数据库遗失后停止启动，不自动创建空站。
- `wp-content/db.php` 在数据库初始化时加载固定版本的系统驱动。
- MU 插件提供后台诊断及更新约束；系统驱动不进入普通插件停用/卸载流程。
- 默认 WAL、FULL、5 秒锁等待、1000 页自动 checkpoint。不重试整个业务请求。
- 数据及备份优先放在公开根目录之外；Web 安装器可使用经过真实 HTTP 拒绝验证的
  公开目录内受保护存储。网络共享磁盘不在支持范围内。
- 根目录 wp-config.php 是不可变入口，站点专属配置位于 wplite/site-config.php；
  未配置时只能转到所有者密钥认证的 SQLite 安装器，不进入 MySQL 向导。

## 上游与发布

WordPress 来源唯一为 https://github.com/WordPress/WordPress.git ，只按稳定标签
同步；SQLite 来源为 WordPress 社区驱动仓库的正式发行。依赖固定 SHA，驱动发行
归档另校验 SHA-256。保留官方核心文件的原始字节，新增系统组件作为独立提交。

项目维护时执行 rebase；用户站点升级时安装经过验证、签名的整套发行包。两条
流程严格区分。WordPress 自身版本字段不改写，WPLite 单独维护版本。

## 兼容与性能门槛

兼容性精确到核心、驱动、PHP、插件、主题及业务流程。HTTP 200 和安装成功均
不能代替功能验证。WooCommerce 要覆盖 HPOS、旧订单存储、同步开关、库存竞争、
双用户购物车、订单状态、退款、重复支付回调和 Action Scheduler。

资源测试与合理配置的 MariaDB 对照，保持核心、插件、主题、数据、PHP 并发及
缓存条件一致。测量总内存、CPU、P95、吞吐和锁错误率；独立/共享数据库分开报告。
不预先承诺节省百分比。ePHPm 只作为后续运行时评测候选。

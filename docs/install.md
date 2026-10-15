# 安装、更新与已有站点

## 全新站点

准备未配置的官方 WordPress、当前 WPLite 仓库、Python 3.9+、满足 README 要求的
PHP/PDO SQLite。只需 `prepare` 和 `tools/install.php`，然后访问官方安装页面。
`prepare` 不包含 WordPress，不提供 `sync`、`rebase`、`build`、备份或恢复命令。
安装期间应由主机限制外部访问，并立即完成官方安装页面；本版本不再提供所有者
一次性密钥或自有 Web 安装认证，不能把未初始化站点长时间公开。

配置工具写入的数据库连接常量及 bootstrap 在普通 wp-config.php 中，密钥本地随机
生成，不请求远程 salt 服务。数据库和配置权限 0600，私有目录 0700；PHP 执行用户
必须有相应权限。初始化空 SQLite 文件后才生成配置；数据库以后丢失将返回 503，
即使访问安装页面也不会重新生成空数据库。

必须准确指定 `--public-root`：例如域名 document root 为 `/srv/www`，WordPress
位于 `/srv/www/blog`，则公开根是 `/srv/www`，不能写成 `/srv/www/blog`。数据库不能
被其他虚拟主机、Alias、CDN 静态映射等额外路径暴露；这些映射由主机所有者负责。
不接受“随机目录名应该猜不到”代替私有存储。

标准布局如下；vendor 由 prepare 生成而非手写：

```text
WordPress/
  wp-config.php                       # 站点配置，不是核心补丁
  wp-content/
    db.php                            # SQLite drop-in
    mu-plugins/
      wplite.php                      # WordPress 自动加载入口
      wplite/
        bootstrap.php
        environment.php
        vendor/sqlite/                # 固定的社区驱动及许可证
```

## WordPress 与 WPLite 分别更新

核心、普通插件、主题沿用 WordPress 自带更新机制，不必等 WPLite 重打整站包。
更新前由所有者另行安排 SQLite 一致性备份，并根据兼容测试结果评估风险。

MU 插件不会获得标准插件更新通知，WPLite 当前需人工部署。配置工具仅用于新站，
不能拿来更新已有站点。更新 WPLite/驱动时，先在副本验证，再于主机维护窗口部署
经过准备和核对的完整 drop-in/MU 目录；不要边服务边零散覆盖依赖。不要启用另一个
SQLite/数据库 drop-in 管理插件来争用 db.php。没有自动卸载/切回 MySQL 功能。

## 已有 MySQL 或旧 WPLite 站点

当前没有数据迁移或自动原地升级工具。不要覆盖已有 wp-config.php、db.php 或删除
旧私有 site.json/数据目录。旧发行版站点继续需要与原版本匹配的工具；新配置工具
会拒绝此类站点。后续迁移必须单独规划、备份并在副本上验证。

本次代码移除不会主动连接、扫描、升级或删除任何已部署站点的文件和数据库。

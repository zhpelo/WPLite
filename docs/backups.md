# 数据库备份与恢复

当前是开发版数据库后端，已在 PHP 8.4.26＋SQLite 3.53.4 验证。Web 恢复
界面、全站文件备份和商店认证仍未完成。不能把这里的数据库验证理解为整站或
WooCommerce 的生产认证。

## 命令与运行依赖

| 命令 | 作用 | 是否加载 WordPress |
|---|---|---|
| `backup-create` | 创建一致性数据库快照 | 是，需要健康站点 |
| `backup-verify` | 验证清单、HMAC、数据校验和、完整性和站点身份 | 否 |
| `restore` | 停写、排空请求，恢复已验证快照 | 否 |
| `restore-status` | 查看维护日志及恢复阶段 | 否 |
| `restore-rollback` | 在维护窗口内恢复被保留的原数据库 | 否 |
| `restore-complete` | 检查当前数据库及系统文件后明确重新开放 | 否 |

这些 CLI 命令以文件系统所有者身份操作。它们不是公开 HTTP API，也不能替代
后续 Web 界面的登录、权限、CSRF 和独立恢复凭据。

```sh
php wplite/cli.php backup-create
php wplite/cli.php backup-verify --backup-dir /private/site/backups/BACKUP-ID --data-dir /private/site
php wplite/cli.php restore --backup-dir /private/site/backups/BACKUP-ID --data-dir /private/site
```

默认快照保存到私有数据目录的 backups 子目录。可通过
`backup-create --output-dir /private/NEW-DIRECTORY` 指定新的目录；必须位于真实
公开根目录外，或属于安装时已通过 HTTP 拒绝验证的同一数据目录；父目录已经存在，
目标本身不存在。不会覆盖已有快照。更换主机/服务器规则后需重新核实保护，参见
[Web 安装与目录保护](web-install.md)。

## 快照与校验

通过 VACUUM INTO 读取一致性数据库视图，包含 WAL 中已提交的数据。完成后同步
快照文件，运行 integrity_check，确认站点身份和 WordPress 数据库版本，再写入
带 SHA-256、大小和版本信息的 HMAC 清单。没有完整清单的目录不被视为有效备份。

认证密钥只保存在私有 site.json，不写入清单、数据库快照或命令输出。恢复需要
原私有状态文件及对应密钥。应另行妥善保管私有状态与配置；当前数据库快照不包含
媒体、配置、插件、主题或认证密钥，因此还不具备整站灾难恢复能力。

校验以只读 immutable 方式打开独立快照，不需要打开或重建线上数据库。未知
密钥、外站快照、清单修改、数据库大小/内容变化和不完整快照均会被拒绝。

## 停写与故障恢复

正常请求持有共享文件锁，贯穿 PHP shutdown 钩子。恢复操作先取得独占维护操作
锁，持久记录 maintenance.json，再等待请求锁排空。维护期间所有新请求返回
503 与 Retry-After，不在请求层自动重放支付或其他写入。

验证并同步暂存快照后，把原数据库及存在的 WAL/SHM 一并保留到私有 rollback
目录，原子替换主数据库，校验身份、完整性、发行文件哈希和 WordPress 核心版本，
成功后才移除维护标记。外部直接打开 SQLite 的脚本不参与该锁协议，需先停止。

```sh
php wplite/cli.php restore-status --data-dir /private/site
php wplite/cli.php restore-rollback --data-dir /private/site
```

异常或进程退出会释放 OS 锁，但保留维护标记。rollback 在当前维护窗口内恢复
保留的原始组件，并保留被替换的恢复文件供检查。如果没有原数据库可退回，或希望
接受已经恢复的快照，先修复匹配的系统文件，再执行：

```sh
php wplite/cli.php restore-complete --data-dir /private/site
```

完成检查通过才重新开放。维护窗口结束后，不提供自动回退此前数据库的命令，
避免覆盖恢复营业后产生的新订单。整个恢复是显式回到快照时刻，不能保留快照之后
的写入。完整站点恢复、插件文件版本匹配和组合升级回退将在后续里程碑完成。

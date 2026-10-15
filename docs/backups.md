# 备份与恢复

当前提供开发版数据库备份/恢复，以及全站快照创建/独立校验后端，已在 PHP
8.4.26＋SQLite 3.53.4 验证。全站替换恢复、Web 认证恢复界面和商店认证仍未
完成。不能把快照校验理解为完整灾难恢复、插件业务一致性或生产认证。

## 命令与运行依赖

| 命令 | 作用 | 是否加载 WordPress |
|---|---|---|
| `backup-create` | 创建一致性数据库快照 | 是，需要健康站点 |
| `backup-verify` | 验证清单、HMAC、数据校验和、完整性和站点身份 | 否 |
| `restore` | 停写、排空请求，恢复已验证快照 | 否 |
| `restore-status` | 查看维护日志及恢复阶段 | 否 |
| `restore-rollback` | 在维护窗口内恢复被保留的原数据库 | 否 |
| `restore-complete` | 检查当前数据库及系统文件后明确重新开放 | 否 |
| `site-backup-create` | 排空请求后创建数据库与全部站点文件的快照 | 否 |
| `site-backup-verify` | 独立核验全站快照 HMAC、完整文件树与数据库 | 否 |
| `site-backup-complete` | 检查原站后结束被杀进程留下的全站备份维护窗口 | 否 |

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

## 全站快照

```sh
php wplite/cli.php site-backup-create --data-dir /private/site
php wplite/cli.php site-backup-verify --data-dir /private/site --backup-dir /private/site/backups/site-BACKUP-ID
```

若快照输出在公开根内的受保护数据树中，创建还必须提供
`--public-origin https://example.com`（scheme/host/port，不带站点路径）。每次先用
随机公开控制响应和新快照目录中的数据库、JSON、PHP 配置、图片及文本挑战验证
HTTP 403/404 拒绝，完成清理后才复制真实内容。不沿用安装时旧证明来推断新增
PHP/媒体路径也安全，不接受重定向、500 或带挑战内容的拒绝响应。需要 loopback
HTTP(S)、有效 TLS 和可写的公开控制探针位置；只有 loopback 主机可用 HTTP。
输出到公开根之外的快照不需要 origin，也不会发起这些 HTTP 请求。

创建过程不加载 WordPress、插件或主题，先持有独占维护操作锁、写入持久标记，
等待所有正常请求（含 shutdown）释放共享锁。数据库使用 VACUUM INTO，文件
以流式复制并同步落盘，最后重新扫描来源，确认文件树/内容/元数据未变化；完整
清单写入后再独立校验。外部 FTP、直接数据库脚本、自建队列等不参与请求锁的
写入者必须先停止；仅靠文件复查不能对抗仍在写入的外部服务。

快照目录包含 `database.sqlite`、认证的 `manifest.json` 和 `site/` 文件树。
包含核心、媒体、站点专属配置、插件/主题、隐藏文件、空目录及原权限记录；只排除
绑定的私有数据树，避免把在线 WAL、锁、旧备份和回滚目录递归复制进去。公开根
目录内的受保护数据目录也会被精确排除，并记录在 `excluded_paths`。默认不静默
排除缓存或其他未知站点文件。符号链接、硬链接、FIFO/设备等特殊文件不受支持，
会拒绝整个快照；当前上限为 50,000 个文件/目录、16 MiB 清单、64 层相对路径。

全站快照包含配置盐值和可能存在的应用秘密，**没有加密**。目录/文件均为仅所有者
可访问；输出只能在公开根目录外，或同一已验证 HTTP 拒绝的数据目录内。不能把
快照当成公开发行 ZIP 上传。备份转移到其他介质后也须保留私密权限，并在离站
保存时使用可信的加密存储。目录格式不依赖 Zip/Phar 扩展，复制大文件不整文件
载入 PHP 内存；文件清单本身仍随文件数量占用内存。
创建/结束维护需要启用 fsync 与 flock；不会在主机禁用这些能力时静默降低保证。

私有 `site.json` 的安装元数据会记录在清单，但刻意排除 `backup_key`。配置盐值
不是备份认证密钥。必须另外妥善保存原始私有状态/认证密钥（例如独立加密保险库），
不能信任备份包自行提供的密钥来认证自身。当前命令面向原站路径，不提供迁移、
密钥遗失找回或全站替换恢复。数据库恢复命令会拒绝 kind=site 的快照，不能据此
声称媒体和插件已恢复到同一时刻。

普通复制/权限/校验失败没有替换原站文件或数据库，可关闭本次维护窗口；半成品
目录会保留供检查，不覆盖既有备份。若进程在复制时被杀，维护标记保留、HTTP
继续 503，半成品不会被视为完整备份。检查原因与原站后显式执行：

```sh
php wplite/cli.php restore-status --data-dir /private/site
php wplite/cli.php site-backup-complete --data-dir /private/site
```

该命令只结束 `site-backup` 类型的非替换维护窗口，不执行恢复、不重试复制，且
必须通过原数据库身份/完整性及受管运行文件检查。不能用它结束数据库恢复窗口，
也不能用数据库 `restore-complete` 误结束尚未处理的全站备份窗口。

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

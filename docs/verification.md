# 本地验证记录

日期：2026-10-15。平台：macOS arm64。

## 固定输入

- WordPress 稳定标签：7.1.3。
- 官方基线 SHA：fc9832bef919c2a08541248db23af90d14ffb082。
- SQLite Database Integration：v3.1.0，SHA 6546e4538b708a1d8a565a1d31688bd63b610fa3。
- 集成提交：664346a5ae700e08c3f0e9ec4dfe005d206d9b11。
- 测试运行时：隔离编译的 PHP 8.4.26，PDO SQLite，SQLite 3.53.4。
- 测试 PHP 无 mysqli/pdo_mysql，不使用主机上的 MySQL 服务，邮件和外部 HTTP 被测试夹具拦截。

## 执行结果

`python3 -m unittest discover -s tests -v`：22 项通过，覆盖正式版本筛选、
数字排序、标签 SHA 变更、依赖锁、归档路径/符号链接、跨系列确认、真实 Git
rebase、冲突保留/恢复、锁变更拒绝、main/本地文件保留、核心篡改拒绝、显式
overlay 更新及 PR 发布目标/自动候选识别，以及驱动独立升级、过时文件移除、
候选去重与核心/驱动版本共同判定。

`php tests/environment.php` 和隔离测试 PHP 的同一检查：均通过。

`python3 tests/smoke.py --php .cache/test-runtime/install/bin/php`：通过。
测试从实际发行 ZIP 安装站点，验证真实 PDO SQLite 数据库和 WordPress HTTP
请求，没有用 mock 数据库替代安装验证。覆盖内容 CRUD、中文/emoji、序列化
option、自定义表 dbDelta、自增、事务回滚、integrity_check、WAL/FULL/锁等待、
核心更新保护、首页、REST、后台登录和访客隔离。重复安装拒绝；数据库/drop-in
缺失均返回 503，不重建或改变数据库。

数据库备份/恢复同样使用实际 PDO SQLite：验证 WAL 已提交内容的一致性快照、
HMAC/摘要/站点身份、篡改及越权目录拒绝、活动事务与互斥锁等 12 项保护。
恢复验证覆盖请求 shutdown 排空、等待超时、原数据库保留、健康检查失败后
维持 503、独立回退/完成、核心版本不匹配拒绝、维护进程被杀后保持停写，以及
不加载 WordPress 即可从数据库遗失状态恢复。此处仅为数据库备份，不是全站备份。

两次从相同集成 SHA 构建的 ZIP 字节一致。本地最终开发包：

`dist/wplite-0.1.0-dev-wp-7.1.3.zip`

SHA-256：`158eb5583e9b74bc4654acbf32ad907f904606cdc8e535ea6d4fa5df3b5a8da0`

打包文件数：5064。与固定官方基线中所有文件逐字节比对，未修改或删除核心文件。
两个 GitHub Actions YAML 已完成本地语法解析。

## 证据边界

这是 M1 开发构建验证，不是生产认证。尚无真实 GitHub Actions 运行/PR 记录，
也未完成 PHP 8.3/8.5、Apache/Nginx、受限虚拟主机、WooCommerce/插件组合、
资源基准、签名升级、全站备份/Web 恢复、磁盘满/权限错误及升级中断演练。
相同 ZIP 校验值在本地同一构建工具链
上得到验证，尚未证明不同 Python/zlib 工具链之间也字节一致。

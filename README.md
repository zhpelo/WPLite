# WPLite

默认使用 SQLite、跟随 WordPress 官方稳定标签的轻量发行版。

当前是开发阶段，尚未认证用于生产商店。首版保留标准 PHP 部署方式，不依赖
ePHPm、MySQL 或 Redis。产品采用 GPL-2.0-or-later；发行包保留 WordPress 及
SQLite 驱动各自的许可和版权信息。

## 开发与构建

需要 Git、Python 3.10+；PHP 检查需要 PHP 8.3–8.5。工具不依赖第三方 Python 包。

```sh
python3 -m unittest discover -s tests -v
php tests/environment.php
python3 tools/wplite.py check
python3 tools/wplite.py sync
python3 tools/wplite.py verify
python3 tools/wplite.py build
```

`check` 只查询正式标签。首次 `sync` 创建以锁定官方标签为底的集成候选；有新维护
版本时在候选分支上执行 `rebase --onto 新基线 旧基线`，不切换或改写 `main`。
冲突保留在 `.cache/candidates/` 中，锁文件保持原样。跨发布系列需要明确指定：

```sh
python3 tools/wplite.py sync --tag 7.2 --approve-series
```

该命令仅在官方实际存在对应稳定标签时才会执行，示例不代表当前版本。
初始基线为 WordPress **7.1.3**、SQLite Database Integration **3.1.0**，完整
提交 SHA 和驱动 ZIP 校验值见 [依赖锁](upstream.lock.json)。开发分支、Beta、RC
和 nightly 均不参与同步或构建。

SQLite 驱动独立跟踪社区正式发行。没有核心更新时，新驱动也能生成候选；ZIP
摘要和标签 SHA 共同固定，同标签替换、草稿、预发行及降级均会被拒绝。

产物位于 `dist/`，包含固定文件时间与顺序的 ZIP、SHA-256 校验文件和依赖清单。
构建只读取已锁定的集成提交；本地未提交的源码不会悄悄进入安装包。修改发行组件
后需运行 `python3 tools/wplite.py sync --refresh-overlay` 创建并验证新的集成候选，
再提交更新后的依赖锁。

## 安装验证

当前具备 CLI 和所有者密钥认证的 Web 安装原型，支持私有目录与经过真实 HTTP
拒绝验证的公开目录存储。见 [Web 安装与保护](docs/web-install.md) 和
[发行包安装说明](runtime/root/WPLITE.md)。不得直接升级线上 WordPress
或将本构建用于真实交易。

已增加 CLI 数据库备份、校验和恢复，支持停写排空、失败后的独立回退及重新开放。
这是数据库后端验证，尚不包含全站文件备份或 Web 恢复界面，详见
[数据库备份与恢复](docs/backups.md)。

若本机 PHP 缺少 PDO SQLite，可选择编译隔离的测试运行时：

```sh
python3 tools/prepare_test_runtime.py
.cache/test-runtime/install/bin/php tests/environment.php
```

该命令只写 `.cache/test-runtime/`，需要 C 编译器和 make，不安装系统软件，不修改
已有 PHP 配置，也不启动系统服务。它是最小化测试用 PHP，不是生产 PHP 安装器。

## 项目文档

- [架构与同步约定](docs/architecture.md)
- [交付路线与完成证据](docs/roadmap.md)
- [上游同步操作](docs/upstream-sync.md)
- [Web 安装与数据目录保护](docs/web-install.md)
- [本地与 GitHub 验证记录及尚未验证的范围](docs/verification.md)

## 启用 GitHub 工作流

`.github/workflows/ci.yml` 负责工具测试、真实 SQLite 安装验证与开发包构建。
`upstream-sync.yml` 每 6 小时检查正式标签，验证候选后创建草稿同步 PR，并去重及
关闭已被更高版本取代的自动候选。跨发布系列只通过手动触发并明确批准。

main 的系统文件和验证代码变更也会触发检查。候选去重会核验实际 Git 来源及
系统/验证指纹，不只比较版本号；人工接手或来源不明的 PR 不会自动关闭。
CI 使用 `verify --overlay` 拒绝依赖锁仍指向旧系统文件的构建。

先将工具 main 和锁中记录的集成分支推送到项目自己的 GitHub 仓库，再在仓库
Settings → Actions 中允许 GitHub Actions 创建 PR。fork 仓库还需主动启用定时
工作流。GitHub Actions 的调度可能延迟，因此同时提供 workflow_dispatch。
工作流文件存在不代表远程定时任务已启用；绑定 origin 和实际运行结果需另行确认。

本仓库 `zhpelo/WPLite` 已启用两条工作流，首次 Ubuntu CI 和手动稳定标签同步
均已通过。当前上游无更新，因此未创建同步 PR；具体证据和边界见
[验证记录](docs/verification.md)。

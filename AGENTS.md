# WPLite 开发约束

## 目标与工作原则

本项目的当前目标是快速上线：不修改 WordPress 核心，用精简 MU 插件和 SQLite
drop-in 完成全新站点安装。优先交付能直接上传使用的 PHP 文件，不构建发行平台。

- 只实现用户明确要求的功能；采用能完成任务的最小改动。
- 不因“以后可能需要”增加抽象、依赖、配置项、管理页面或基础设施。
- 范围有实质变化时先询问用户，不自行扩大任务。
- README 面向安装用户，必须与实际目录和安装行为一致，不加入维护者终端流程。

## 当前目录与加载方式

- `webroot/`：可直接上传到 WordPress 根目录的内容，不包含 WordPress 核心。
- `webroot/wplite-setup.php`：仅用于全新站点的浏览器安装入口。
- `webroot/wp-content/mu-plugins/wplite.php`：MU 插件入口。
- `webroot/wp-content/mu-plugins/wplite/setup.php`：一次性安装逻辑，不在正常请求中加载。
- `webroot/wp-content/mu-plugins/wplite/bootstrap.php`、`environment.php`：必要的启动与环境检查。
- `webroot/wp-content/mu-plugins/wplite/db-template.php`：安装时复制为 `wp-content/db.php`。
  模板内部路径以安装后的 drop-in 位置为准，不要按模板所在目录改写。
- `webroot/wp-content/mu-plugins/wplite/vendor/sqlite/`：内置上游驱动，保留许可证和版权声明。

配置加载 bootstrap 后进入官方 `wp-settings.php`；数据库通过 `wp-content/db.php`
加载 SQLite 驱动。不要仅依赖 MU 插件加载时机初始化数据库。

## 安装和运行底线

- 用户只能上传文件和使用浏览器，也必须能完成安装。不要要求 SSH、CLI、Python、
  Composer、构建步骤或安装时下载依赖。
- 仅支持全新站点，不覆盖已有 `wp-config.php`、数据库 drop-in 或站点数据。
- 保留必要的所有者凭据校验、防重复安装和数据库访问保护，不为减少代码移除安全底线。
- 数据库优先放在公开目录之外。站内存储必须先确认服务器拒绝下载敏感文件；
  不以随机路径、PHP 检查或未经验证的 `.htaccess` 代替服务器访问控制。
- 安装失败明确报错，不静默重建丢失的数据库，不自动删除用户数据或开发恢复流程。
- 不自行编写 SQL 翻译器，不直接修改上游 vendor 源码。仅在明确要求升级依赖时
  核对来源、版本和归档校验值，保留原始文件及许可证。

## 明确不做

未经用户新指令，不创建或恢复以下内容：

- WordPress 核心修改、核心源码入库、上游同步/rebase、自动 PR 或整站发行。
- `tools/`、`checks/`、`tests/`、测试框架、兼容性矩阵或 CI 平台；此前测试目录的删除是用户决定。
- 备份恢复、MySQL 迁移、多站点支持、自动更新器、后台管理功能或常驻 PHP/ePHPm。

保留 WordPress 核心、普通插件和主题的原有更新机制，不添加更新拦截。
MU 插件和驱动当前需要手动部署更新，不宣称已具备普通插件自动更新能力。

## 修改与验证

- 保留用户现有修改和目录调整，不擅自恢复已删除文件，不因文档任务修改运行代码。
- 按改动风险做最小必要验证，例如 PHP 语法检查或一次针对性的安装确认；
  不把验证扩展成新工具、新测试产品或环境建设项目。
- 不替换系统 PHP，不更改或启动系统服务，不操作真实站点数据来验证功能。
- 只报告实际执行结果，区分本地验证与真实主机验证。不承诺全部插件、主题、
  未来 WordPress 版本兼容，也不编造资源节省或性能提升比例。
- 未经明确要求，不提交、推送、发布、创建 PR 或修改远程仓库设置。

# SHOPAGG Woo Theme 开发文档

SHOPAGG Woo Theme 是以 WoodMart 8.5.2 为基础维护的 WooCommerce 商城主题。当前发行版将后台品牌统一为“SHOPAGG主题”，安装目录与 SHOPAGG 应用商店远程资源标识为 `shopagg-woo-theme`，同时保留大量 `woodmart`、`xts`、`wd` 内部标识以兼容既有数据库、模板、插件和第三方扩展。

> 本仓库是可直接运行的发行态主题，不是完整的上游源码构建仓库。编译后的 CSS/JavaScript 已随主题提交，但仓库内没有 Composer、npm、PHPUnit 或前端打包配置。修改生成产物前，应先确认团队是否另有私有构建仓库。

## 基本信息

| 项目 | 当前值 |
| --- | --- |
| 后台显示名称 | SHOPAGG主题 |
| WordPress 主题目录 | `wp-content/themes/shopagg-woo-theme` |
| SHOPAGG 远程资源 slug | `shopagg-woo-theme` |
| 主题版本 | `8.5.2` |
| 最低 PHP 版本 | `7.4` |
| WordPress text domain | `woodmart` |
| 主题设置 option | `xts-woodmart-options` |
| 核心插件最低版本 | SHOPAGG 主题核心 `1.1.9` |
| 支持的构建器 | Gutenberg、Elementor、WPBakery |
| 更新提供方 | SHOPAGG 应用商店插件 |

## 开始开发

1. 使用与生产站一致的 WordPress、WooCommerce、PHP 和数据库版本建立独立开发环境。
2. 安装并启用本主题、SHOPAGG 主题核心、WooCommerce，以及站点实际使用的页面构建器。
3. 进入“SHOPAGG主题 → 状态”检查 PHP 限制、可写目录和插件版本。
4. 备份数据库中的主题选项、Presets、Header Builder 数据和站点内容。
5. 业务级定制优先放在子主题或站点插件；只有平台能力、品牌、导入器及更新链路才直接修改本主题。
6. 修改后至少验证首页、商品列表、商品详情、购物车、结账、账户页、后台主题设置和更新检查。

子主题的 `style.css` 必须使用新目录名：

```css
/*
Theme Name: SHOPAGG Woo Child
Template: shopagg-woo-theme
Text Domain: shopagg-woo-child
*/
```

详细环境和验证步骤见 [开发环境与快速开始](docs/开发环境与快速开始.md)。

## 文档导航

| 文档 | 解决的问题 |
| --- | --- |
| [文档总览](docs/文档总览.md) | 按角色和任务选择阅读顺序 |
| [架构与启动流程](docs/架构与启动流程.md) | 主题如何加载、模块如何组织、关键依赖是什么 |
| [开发环境与快速开始](docs/开发环境与快速开始.md) | 如何搭建、备份、调试和验证 |
| [扩展开发指南](docs/扩展开发指南.md) | 如何用子主题、插件、Hook 和模板安全扩展 |
| [主题设置与数据模型](docs/主题设置与数据模型.md) | 选项、Preset、Header、CPT、缓存存在哪里 |
| [后台菜单与品牌](docs/后台菜单与品牌.md) | SHOPAGG 品牌层、菜单归组及兼容边界 |
| [WooCommerce 开发](docs/商城开发.md) | 模板覆盖、商城模块、定时任务和升级检查 |
| [页面构建器与 Layouts](docs/页面构建器与布局.md) | Gutenberg、Elementor、WPBakery 与 Layout Builder |
| [资源、样式与性能](docs/资源样式与性能.md) | CSS/JS 注册、按需加载、缓存和性能注意事项 |
| [更新与发布](docs/更新与发布.md) | SHOPAGG 应用商店更新协议、目录规范和发版流程 |
| [Prebuilt Websites 技术设计](docs/预建网站技术设计.md) | 模板远程数据、导入状态机、媒体下载与完整性校验 |
| [测试与故障排查](docs/测试与故障排查.md) | 日志、超时、导入、插件安装、前后台问题定位 |
| [安全开发清单](docs/安全开发清单.md) | AJAX、REST、上传、权限、Nonce 和远程下载要求 |
| [常见问题](docs/常见问题.md) | 二次开发中最容易误判的问题 |

## 目录速览

```text
shopagg-woo-theme/
├── functions.php                 # 常量、基础类加载、主题启动
├── style.css                     # WordPress 主题元数据与主样式
├── theme.json                    # Gutenberg 全局样式最小配置
├── inc/
│   ├── admin/                    # 后台、设置、导入、向导和管理资源
│   ├── classes/                  # Theme、Registry、API、更新等核心类
│   ├── configs/                  # CSS/JS、模板版本和结构化配置
│   ├── integrations/             # WooCommerce、构建器及第三方插件集成
│   ├── modules/                  # Header、Layouts、搜索、性能等功能模块
│   ├── shortcodes/               # 公共短代码渲染
│   ├── template-tags/            # 模板辅助函数
│   └── widgets/                  # 小工具
├── header-elements/              # Header Builder 元素模板
├── templates/                    # 主题页面片段
├── woocommerce/                  # WooCommerce 模板覆盖
├── css/、js/                     # 已构建的前后台资源
└── docs/                         # 本开发手册
```

当前代码量较大：约 1,792 个 PHP 文件，其中 `inc/` 约 1,641 个；WooCommerce 模板覆盖约 93 个；随主题提供约 189 组预建站点数据。开发时应先定位模块入口，不建议进行无范围的全局替换。

## 兼容性红线

除非同时提供数据迁移、兼容层和完整回归测试，否则不要修改以下标识：

- PHP 函数、Hook、类和常量中的 `woodmart_*`、`WOODMART_*`；
- 数据库 option：`xts-woodmart-options`、`xts-options-presets`、`woodmart_version` 等；
- 自定义内容类型和分类法：`woodmart_layout`、`cms_block`、`woodmart_slide`、`wd_popup` 等；
- text domain `woodmart`；
- 后台 page slug，例如 `xts_dashboard`、`xts_plugins`、`xts_prebuilt_websites`；
- 主题与 SHOPAGG 主题核心之间约定的 AJAX action、meta key、短代码和 CSS 类名。

“SHOPAGG主题”是用户界面品牌；`shopagg-woo-theme` 是安装和远程更新标识；`woodmart`/`xts`/`wd` 是内部兼容协议。三者不应混为一谈。

## 开发原则

- 不在生产站直接编辑父主题。
- 修改前记录触发路径、Hook、option、缓存和构建器分支。
- 优先使用 Hook；其次使用子主题模板覆盖；最后才修改父主题核心。
- 不把主题选项、Token、临时下载地址或用户数据写入 Git。
- 不只验证“请求成功”，还要验证商品、附件、页面、菜单和设置的最终状态。
- 更新 WooCommerce 前审计 `woocommerce/` 覆盖；更新本主题前审计 SHOPAGG 定制。
- 发布包的顶层目录必须最终安装为 `shopagg-woo-theme`。

## 相关组件

本主题不是独立系统。完整运行链路至少涉及：

- `wp-content/plugins/shopagg-woo-theme-core`：自定义内容类型、导入器和核心功能；
- `wp-content/plugins/shopagg-app-store`：主题登记、远程版本检查和安装包下载；
- WooCommerce：商品、订单、模板 API 与 Action Scheduler；
- 当前页面构建器：Gutenberg、Elementor 或 WPBakery；
- SHOPAGG App Store 服务端：以 `shopagg-woo-theme` 发布主题版本。

遇到问题时，先确定责任属于主题、SHOPAGG 主题核心、SHOPAGG 应用商店插件、服务端资源，还是主机环境，再开始修改。

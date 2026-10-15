# 官方稳定标签同步

## 引用与分支

`main` 保存工具和依赖锁。`upstream` 只读取官方 WordPress 仓库；获取的正式
标签保存在 `refs/tags/upstream/<版本>`。不配置开发分支的 tracking branch。
upstream 的 push URL 固定为 DISABLED，避免误向官方仓库推送。

每次集成在 `sync/wp-<版本>-<唯一编号>` 中重放 WPLite 提交。已发布的
`wplite/<发行版本>` 标签不可移动。发布时固化 `wplite/wp-<核心版本>` 集成分支；
同核心版本的新发行只追加提交，不重写已发布历史。候选和发布分支不合并回工具
main：main 的同步 PR 只更新依赖锁，并提供候选分支和可审查差异。

## 本地操作

```sh
python3 tools/wplite.py check
python3 tools/wplite.py sync
python3 tools/wplite.py verify
python3 tools/wplite.py verify --overlay
python3 -m unittest discover -s tests -v
python3 tools/wplite.py build
```

本地执行不会推送或创建远程 PR。初次 sync 会获取官方稳定标签的 Git 历史，
随后创建并锁定集成提交。相同锁、相同集成 SHA 的构建输出一致。

check 同时读取 SQLite 社区驱动的正式 GitHub Release；只有已发布、非草稿、非
预发行版本，并且具备预期 ZIP 资产和发布的 SHA-256 摘要时才接受。数字 Git 标签
本身不等于正式发行。sync 即使没有核心更新，也会为新驱动生成独立候选。手动
指定驱动可使用 `sync --sqlite-tag vX.Y.Z`，同样必须是已经存在的正式发行，不允许
降级或在相同标签下替换 SHA/资产摘要。

驱动升级会移除旧版本中已不再存在的受管文件，新增文件均写入完整 SHA-256
清单。PR 去重和取代按“核心版本、驱动版本”共同比较；核心较新但驱动较旧的
候选不会关闭包含更新驱动的候选。

## 待审候选的新鲜度

除了每 6 小时及手动执行，main 中 runtime、工具、测试、工作流和相关验证配置
变化也会触发同步检查。没有上游更新时仍不创建空 PR；锁定集成必须先通过
`verify --overlay`，未显式刷新系统文件的 main 会失败，不会静默发行旧组件。
普通 `build` 仍只读取锁定 SHA，不会把开发者未提交的文件悄悄打包。

去重身份同时包含核心和驱动的完整固定记录、WPLite 版本、系统文件指纹，以及
工具/测试/工作流/.gitattributes/AGENTS.md 的验证指纹。PR 中展示的指纹供审查，
不是可信输入；发布器读取 PR 实际 head 的锁和 Git 对象，核验其为 main 祖先之上
仅修改依赖锁的单提交，并确认集成中的系统文件与该来源提交匹配。

相同版本但系统文件或验证代码已变化时，新候选须重新测试，再创建新的草稿 PR。
确认替代关系后可关闭旧候选，但保留全部分支和提交；只改非发行文档不会重复创建。
核心较新、驱动较旧时不会关闭包含较新驱动的候选。

自动管理只针对 github-actions[bot] 在本仓库创建、具有专用标记和分支格式的 PR。
人工创建、追加人工提交、改写为人工单提交、来源不明或不能验证的候选均保留供审核。可移除自动管理
标记以明确转为人工维护。保留候选和关闭旧候选之前都会重新检查 PR head/目标分支。

发布前、创建 PR 前及关闭旧候选前会复查远端 main。检测到 main 推进则中止；
若分支已推送会保留以便检查，不强推、不删除、不自动合并。GitHub PR API 不能与
Git 引用检查组成原子事务，这些检查不替代后续的严格分支保护、最新基线 CI 和人工
审核；遇到并发变化应在最新 main 上重跑。相关输入 push 会排队触发新检查，文档
等其他变更则可手动重跑或等待下一次定时检查。

源文件直接读取 Git tree/blob，不使用会执行 export-ignore/export-subst 的归档
视图；忽略规则不能隐藏核心文件或让测试退出验证指纹。首次本地 main 尚未提交时
可以构建初始集成，但 provenance 标记为空，不能通过自动发布的来源提交检查。

rebase 使用显式旧基线作为排除边界。官方镜像的不同发行标签不一定互为祖先，
所以不能使用含糊的 `git rebase upstream/master` 或让 Git 猜测合并基线。

发生冲突时，工具输出候选目录，保留 Git rebase 状态；主目录及原锁不动。维护者
在候选中解决冲突后执行 `git rebase --continue`，然后从工具主目录运行：

```sh
python3 tools/wplite.py resume --candidate /absolute/path/to/.cache/candidates/wp-version-id
python3 tools/wplite.py verify
```

resume 会检查候选来源、官方标签是否变化、原依赖锁是否仍匹配、rebase 是否完成，
重新验证核心与系统组件后才导入结果。不能绕过测试手工发布候选。

## 审核与版本来源

审核需要检查官方标签及 SHA、官方版本文件校验记录、驱动归档校验值、核心字节
一致性、WPLite 本地提交变化和集成测试结果。新发布系列必须使用显式
`--tag <正式标签> --approve-series`。

官方稳定源：https://github.com/WordPress/WordPress

SQLite 驱动：https://github.com/WordPress/sqlite-database-integration

官方发行校验接口：https://api.wordpress.org/core/checksums/1.0/

## GitHub 执行条件

工具与集成分支均需推送到项目自己的 origin；启用 Actions 并允许工作流创建 PR。
自动候选为草稿，必须经过维护者审核，不自动合并或发布。

GitHub 默认不会为 GITHUB_TOKEN 创建的 PR 触发另一次 pull_request 工作流。
本同步工作流在创建 PR 前已经验证候选，并上传测试产物；若分支保护要求 PR
自己的 CI 状态，可在 Actions 中对对应 bot 分支手动运行 Validate WPLite。
不能把没有运行记录的工作流文件当作验证证据。
